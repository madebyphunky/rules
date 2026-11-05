<?php

namespace App\Domain\Rules;

use App\Domain\Rules\Exceptions\RulesDefinitionException;
use Illuminate\Support\Collection;
use Symfony\Component\Yaml\Yaml;

class RulesDefinitionCompiler
{
    /**
     * @param array<string,mixed>|Collection|string $definition
     *
     * @return array{
     *   config: array<string,mixed>,
     *   inputs: array<string,mixed>,
     *   outputs: array<string,mixed>,
     *   rules: array<int,array<string,mixed>>,
     *   macros: array<string,string>
     * }
     */
    public static function compile(array|Collection|string $definition): array
    {
        if (is_string($definition)) {
            $definition = Yaml::parse($definition) ?? [];
        }

        if ($definition instanceof Collection) {
            $definition = $definition->all();
        }

        if (! is_array($definition)) {
            throw new RulesDefinitionException('Rules definition must be an array, Collection, or YAML string.');
        }

        $config = $definition['config'] ?? [];
        $inputs = $definition['inputs'] ?? [];
        $outputs = $definition['outputs'] ?? [];
        $rules = $definition['rules'] ?? [];
        $macros = $definition['macros'] ?? [];

        if (! is_array($rules)) {
            throw new RulesDefinitionException('"rules" must be an array.');
        }

        if (! is_array($macros)) {
            throw new RulesDefinitionException('"macros" must be an array of name => expression strings.');
        }

        // Normalise macros to string expressions
        $normalisedMacros = [];
        foreach ($macros as $name => $expr) {
            if (! is_string($name)) {
                throw new RulesDefinitionException('Macro name must be a string.');
            }

            if (! is_string($expr)) {
                throw new RulesDefinitionException("Macro '{$name}' must be a string expression.");
            }

            $normalisedMacros[$name] = $expr;
        }

        return [
            'config' => is_array($config) ? $config : [],
            'inputs' => is_array($inputs) ? $inputs : [],
            'outputs' => is_array($outputs) ? $outputs : [],
            'rules' => $rules,
            'macros' => $normalisedMacros,
        ];
    }
}
