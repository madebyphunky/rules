<?php

namespace App\Domain\Rules;

use App\Domain\Rules\Exceptions\RulesDefinitionException;
use App\Domain\Rules\Exceptions\RulesetExecutionException;
use Brick\Money\Money;
use Illuminate\Support\Collection;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

class RulesEngine
{
    /**
     * Current macros for the definition being executed.
     *
     * @var array<string,string>
     */
    protected array $macros = [];

    public function __construct(
        protected ExpressionLanguage $language,
    ) {
    }

    /**
     * Bind a rules definition and get a reusable compiled ruleset instance.
     *
     * @param array<string,mixed>|\Illuminate\Support\Collection|string $definition
     */
    public function definition(array|Collection|string $definition): CompiledRuleset
    {
        $compiled = RulesDefinitionCompiler::compile($definition);

        return new CompiledRuleset($this, $compiled);
    }

    /**
     * One-shot: run a definition against a single input.
     *
     * @param array<string,mixed>|\Illuminate\Support\Collection|string $definition
     * @param array<string,mixed>|\Illuminate\Support\Collection $input
     */
    public function run(array|Collection|string $definition, array|Collection $input): RulesResult
    {
        return $this->definition($definition)->run($input);
    }

    /**
     * One-shot: run a definition against many inputs.
     *
     * @param array<string,mixed>|\Illuminate\Support\Collection|string $definition
     * @param iterable<array|Collection> $inputs
     *
     * @return Collection<int,RulesResult>
     */
    public function runMany(array|Collection|string $definition, iterable $inputs): Collection
    {
        return $this->definition($definition)->runMany($inputs);
    }

    /**
     * Execute a compiled definition against one input.
     *
     * This method is public to allow CompiledRuleset to call it, but should not
     * be called directly by application code. Use definition()->run() instead.
     *
     * @param array<string,mixed> $compiledDefinition
     * @param array<string,mixed>|\Illuminate\Support\Collection $input
     */
    public function runWithCompiledDefinition(array $compiledDefinition, array|Collection $input): RulesResult
    {
        if ($input instanceof Collection) {
            $input = $input->all();
        }

        if (! is_array($input)) {
            throw new RulesDefinitionException(
                'Input must be array or Collection, got ' . gettype($input) . '.'
            );
        }

        // Set macros for this run (and reset afterwards)
        $this->macros = $compiledDefinition['macros'] ?? [];

        $config = $compiledDefinition['config'] ?? [];

        // Ensure money config exists with defaults (so config.money.* is always present)
        $config['money'] ??= [];
        $config['money']['currency'] ??= (function_exists('config') ? config('money.default_currency', 'GBP') : 'GBP');
        $config['money']['locale'] ??= (function_exists('config') ? config('app.locale', 'en_GB') : 'en_GB');
        $config['money']['rounding_mode'] ??= (function_exists('config') ? config('money.rounding_mode', 'DOWN') : 'DOWN');

        $definitionInput = $compiledDefinition['inputs'] ?? [];
        $outputs = $compiledDefinition['outputs'] ?? [];
        $rules = $compiledDefinition['rules'] ?? [];

        $context = $this->buildInitialContext($definitionInput, $input);

        // Index rules by rule-id for goto
        $ruleIndexById = [];
        foreach ($rules as $i => $rule) {
            if (isset($rule['rule']) && is_string($rule['rule'])) {
                $ruleIndexById[$rule['rule']] = $i;
            }
        }

        $index = 0;
        $count = count($rules);

        try {
            while ($index < $count) {
                /** @var array<string,mixed> $rule */
                $rule = $rules[$index];

                [$context, $config, $goto] = $this->executeRule(
                    $rule,
                    $context,
                    $config,
                );

                if ($goto === 'return') {
                    break;
                }

                if ($goto === null || $goto === 'next') {
                    $index++;

                    continue;
                }

                if (! is_string($goto) || ! array_key_exists($goto, $ruleIndexById)) {
                    $availableIds = array_keys($ruleIndexById);
                    $availableIdsStr = empty($availableIds) ? 'none' : implode(', ', $availableIds);
                    $ruleId = $rule['rule'] ?? 'unknown';

                    throw new RulesetExecutionException(
                        "Unknown rule id '{$goto}' in 'goto' for rule '{$ruleId}'. Available rule ids: {$availableIdsStr}.",
                        0,
                        null,
                        'UNKNOWN_RULE_ID'
                    );
                }

                $index = $ruleIndexById[$goto];
            }

            $outputData = $this->buildOutputs($outputs, $context, $config);

            return new RulesResult($outputData, $context, $config);
        } finally {
            // Clear macros after run to avoid bleed between different definitions
            $this->macros = [];
        }
    }

    /**
     * Build the initial context from definition inputs + runtime input.
     *
     * @param array<string,mixed> $definitionInputs
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    protected function buildInitialContext(array $definitionInputs, array $input): array
    {
        // Start with definition inputs as defaults
        $context = $definitionInputs;

        // Runtime input overrides / adds values
        foreach ($input as $key => $value) {
            $context[$key] = $value;
        }

        return $context;
    }

    /**
     * Execute a single rule.
     *
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $context
     * @param array<string,mixed> $config
     *
     * @return array{0: array<string,mixed>, 1: array<string,mixed>, 2: string|null}
     */
    protected function executeRule(array $rule, array $context, array $config): array
    {
        $ruleId = $rule['rule'] ?? 'unknown';

        try {
            // Local inputs for this rule start as a snapshot of global context
            $locals = $context;

            // Rule-level inputs (scoped vars)
            if (isset($rule['inputs']) && is_array($rule['inputs'])) {
                foreach ($rule['inputs'] as $name => $raw) {
                    $locals[$name] = $this->evaluateValue($raw, $locals, $config);
                }
            }

            // Decide branch: then or otherwise
            $branch = null;

            if (isset($rule['when'])) {
                $cond = (bool) $this->evaluateValue($rule['when'], $locals, $config);

                if ($cond) {
                    $branch = $rule['then'] ?? null;
                } else {
                    $branch = $rule['otherwise'] ?? null;
                }
            } else {
                // no 'when' means always execute 'then'
                $branch = $rule['then'] ?? null;
            }

            if ($branch === null) {
                // no-op, fall through
                return [$context, $config, 'next'];
            }

            if (! is_array($branch)) {
                throw new RulesetExecutionException(
                    "Rule '{$ruleId}' branch must be an array, got " . gettype($branch) . '.',
                    0,
                    null,
                    'INVALID_BRANCH'
                );
            }

            // Apply set.{inputs,config}
            if (isset($branch['set']) && is_array($branch['set'])) {
                $set = $branch['set'];

                if (isset($set['inputs']) && is_array($set['inputs'])) {
                    foreach ($set['inputs'] as $name => $raw) {
                        $value = $this->evaluateValue($raw, $locals, $config);
                        $context[$name] = $value;
                        $locals[$name] = $value;
                    }
                }

                if (isset($set['config']) && is_array($set['config'])) {
                    foreach ($set['config'] as $name => $raw) {
                        $value = $this->evaluateValue($raw, $locals, $config);
                        $config[$name] = $value;
                    }
                }
            }

            // throw: { message, code? } or string
            if (isset($branch['throw'])) {
                $throw = $branch['throw'];

                if (is_array($throw)) {
                    $message = $throw['message'] ?? "Rule '{$ruleId}' threw an exception.";
                    $codeKey = $throw['code'] ?? 'RULE_THROWN';

                    throw new RulesetExecutionException(
                        "[Rule '{$ruleId}'] {$message}",
                        0,
                        null,
                        $codeKey
                    );
                }

                if (is_string($throw)) {
                    throw new RulesetExecutionException(
                        "[Rule '{$ruleId}'] {$throw}",
                        0,
                        null,
                        'RULE_THROWN'
                    );
                }

                throw new RulesetExecutionException(
                    "Rule '{$ruleId}' threw an exception with invalid format. Expected string or array with 'message' and optional 'code'.",
                    0,
                    null,
                    'RULE_THROWN'
                );
            }

            // goto: 'next' (default), 'return', 'stop', or rule id
            $goto = $branch['goto'] ?? 'next';

            // Normalise boolean goto to 'next'
            if ($goto === true || $goto === false) {
                $goto = 'next';
            }

            if ($goto === 'return' || $goto === 'stop') {
                return [$context, $config, 'return'];
            }

            if ($goto === 'next' || $goto === null) {
                return [$context, $config, 'next'];
            }

            if (! is_string($goto)) {
                throw new RulesetExecutionException(
                    "Rule '{$ruleId}' has invalid 'goto' value: " . json_encode($goto) . '. Expected: "next", "return", "stop", or a rule id string.',
                    0,
                    null,
                    'INVALID_GOTO'
                );
            }

            return [$context, $config, $goto];
        } catch (RulesetExecutionException $e) {
            // Re-throw RulesetExecutionException as-is (already has context)
            throw $e;
        } catch (\Throwable $e) {
            throw new RulesetExecutionException(
                "Rule '{$ruleId}' execution failed: " . $e->getMessage(),
                0,
                $e,
                'RULE_EXECUTION_FAILED'
            );
        }
    }

    /**
     * Check if a string is an expression string ("#! ...").
     */
    protected function isExpressionString(string $raw): bool
    {
        return str_starts_with(ltrim($raw), '#!');
    }

    /**
     * Strip the "#!" marker and leading whitespace.
     */
    protected function stripShebang(string $raw): string
    {
        $trimmed = ltrim($raw);

        if (str_starts_with($trimmed, '#!')) {
            return ltrim(substr($trimmed, 2));
        }

        return $raw;
    }

    /**
     * Expand macros in an expression string.
     *
     * - Macros are defined in the definition under "macros":
     *     macros:
     *       add_delta: "premium + $1"
     *
     * - Used in expressions as:
     *     "#! add_delta(device_band.premium_delta)"
     *
     * - Supports positional parameters $1, $2, ... and access to any context
     *   variable: "premium + $1", "money_plus(premium, $1)", etc.
     */
    protected function expandMacros(string $expression): string
    {
        if ($this->macros === []) {
            return $expression;
        }

        $expr = $expression;

        // We allow nested macro usage, so iterate until no further changes,
        // with a sensible safety cap to avoid infinite recursion.
        $maxPasses = 5;
        for ($pass = 0; $pass < $maxPasses; $pass++) {
            $before = $expr;

            $pattern = '/\b([a-zA-Z_][a-zA-Z0-9_]*)\s*\(([^()]*)\)/';

            $expr = preg_replace_callback($pattern, function (array $matches): string {
                $name = $matches[1];
                $argsString = trim($matches[2]);

                if (! isset($this->macros[$name])) {
                    // Not a macro we know about; leave as-is.
                    return $matches[0];
                }

                $body = $this->macros[$name];

                // Parse args taking into account nested parentheses.
                $args = [];
                $current = '';
                $depth = 0;
                $len = strlen($argsString);

                for ($i = 0; $i < $len; $i++) {
                    $ch = $argsString[$i];

                    if ($ch === '(') {
                        $depth++;
                        $current .= $ch;

                        continue;
                    }

                    if ($ch === ')') {
                        if ($depth > 0) {
                            $depth--;
                        }
                        $current .= $ch;

                        continue;
                    }

                    if ($ch === ',' && $depth === 0) {
                        $arg = trim($current);
                        if ($arg !== '') {
                            $args[] = $arg;
                        }
                        $current = '';

                        continue;
                    }

                    $current .= $ch;
                }

                $last = trim($current);
                if ($last !== '') {
                    $args[] = $last;
                }

                // Replace $1, $2, ... with raw argument expressions.
                $expanded = preg_replace_callback('/\$(\d+)/', function (array $m) use ($args): string {
                    $index = (int) $m[1] - 1;

                    if ($index < 0 || $index >= count($args)) {
                        // No corresponding argument supplied → treat as null
                        return 'null';
                    }

                    $argExpr = $args[$index];

                    return '(' . $argExpr . ')';
                }, $body);

                // Wrap macro expansion in parentheses to keep precedence sane.
                return '(' . $expanded . ')';
            }, $expr);

            if ($expr === $before) {
                break;
            }
        }

        return $expr;
    }

    /**
     * Normalise a DSL expression into an ExpressionLanguage-friendly string.
     *
     * - Assumes "#!" has already been stripped.
     * - Expands macros.
     * - Rewrites dot-notation:
     *      foo.bar.baz → foo['bar']['baz']
     *   for ANY variable (config, device_band, etc).
     */
    protected function normaliseExpression(string $expression): string
    {
        // First expand macros
        $expr = $this->expandMacros($expression);
        $expr = trim($expr);

        // Matches "var.segment.more" where var is an identifier and each ".segment"
        // is also an identifier. We don't try to be too clever; we just avoid
        // matching things like function calls or numeric literals.
        $pattern = '/\b([a-zA-Z_][a-zA-Z0-9_]*)(\.[a-zA-Z_][a-zA-Z0-9_]*)+/';

        $expr = preg_replace_callback($pattern, static function (array $matches): string {
            $full = $matches[0];          // e.g. "device_band.provider_code"
            $parts = explode('.', $full); // ["device_band", "provider_code"]

            $base = array_shift($parts);  // "device_band"
            $rewritten = $base;

            foreach ($parts as $segment) {
                $rewritten .= "['{$segment}']";
            }

            return $rewritten;            // "device_band['provider_code']"
        }, $expr);

        return $expr;
    }

    /**
     * Ensure only "safe" values are exposed to ExpressionLanguage.
     *
     * Allowed:
     *  - null
     *  - scalars
     *  - arrays
     *  - Brick\Money\Money
     *
     * Anything else must be projected to an array/DTO before entering the engine.
     *
     * @param array<string,mixed> $vars
     *
     * @return array<string,mixed>
     */
    protected function sanitizeVars(array $vars): array
    {
        foreach ($vars as $key => $value) {
            if ($value === null || is_scalar($value) || is_array($value)) {
                continue;
            }

            if ($value instanceof Money) {
                continue;
            }

            // You can extend this whitelist later (e.g. DateTimeImmutable, DTOs implementing an interface, etc.)
            $type = is_object($value) ? get_class($value) : gettype($value);

            throw new RulesDefinitionException(
                "Variable '{$key}' is an unsupported value of type '{$type}' in rules context. " .
                'Project it to an array/DTO or scalar before passing it into the rules engine.'
            );
        }

        return $vars;
    }

    /**
     * Evaluate a "value" that may be:
     * - scalar (returned as-is)
     * - string starting with "#!" (ExpressionLanguage)
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed> $config
     */
    protected function evaluateValue(mixed $raw, array $context, array $config): mixed
    {
        if (! is_string($raw)) {
            return $raw;
        }

        if (! $this->isExpressionString($raw)) {
            // literal string
            return $raw;
        }

        $expr = $this->stripShebang($raw);

        return $this->evaluateExpression($expr, $context, $config);
    }

    /**
     * Evaluate an ExpressionLanguage expression with context + config.
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed> $config
     */
    protected function evaluateExpression(string $expression, array $context, array $config): mixed
    {
        // Build vars, then lock them down
        $vars = [
            ...$context,
            'config' => $config,
        ];

        $vars = $this->sanitizeVars($vars);

        // Normalise DSL to ExpressionLanguage:
        // - "#!" already stripped
        // - macros expanded
        // - rewrite dot-notation in all vars (config, device_band, etc.)
        $expr = $this->normaliseExpression($expression);

        return $this->language->evaluate($expr, $vars);
    }

    /**
     * Project context into outputs according to output definitions.
     *
     * Our DSL treats outputs as:
     * - null          → copy from context if present
     * - scalar value  → literal, unless it's an expression string "#! ..."
     * - string starting with "#!" → expression to evaluate
     */
    protected function buildOutputs(array $outputs, array $context, array $config): array
    {
        $result = [];

        foreach ($outputs as $name => $def) {
            // 1) Expression-style: "#! some_expression_here"
            if (is_string($def)) {
                if ($this->isExpressionString($def)) {
                    $expr = $this->stripShebang($def);
                    $value = $this->evaluateExpression($expr, $context, $config);

                    $result[$name] = $value;

                    continue;
                }

                // 2) Plain literal string (no "#!")
                $result[$name] = $def;

                continue;
            }

            // 3) Null definition → copy from context if present
            if ($def === null) {
                $result[$name] = $context[$name] ?? null;

                continue;
            }

            // 4) Anything else (array, int, float, bool) → treat as literal
            $result[$name] = $def;
        }

        return $result;
    }
}
