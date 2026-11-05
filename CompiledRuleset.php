<?php

namespace App\Domain\Rules;

use Illuminate\Support\Collection;

final class CompiledRuleset
{
    /**
     * @param array<string,mixed> $compiledDefinition
     */
    public function __construct(
        private RulesEngine $engine,
        private array $compiledDefinition,
    ) {
    }

    /**
     * Run this compiled ruleset against a single input.
     *
     * @param array<string,mixed>|\Illuminate\Support\Collection $input
     */
    public function run(array|Collection $input): RulesResult
    {
        return $this->engine->runWithCompiledDefinition(
            $this->compiledDefinition,
            $input,
        );
    }

    /**
     * Run this compiled ruleset against many inputs.
     *
     * @param iterable<array<string,mixed>|\Illuminate\Support\Collection> $inputs
     *
     * @return \Illuminate\Support\Collection<int,\App\Domain\Rules\RulesResult>
     */
    public function runMany(iterable $inputs): Collection
    {
        return collect($inputs)->map(
            fn ($input) => $this->run($input),
        );
    }
}
