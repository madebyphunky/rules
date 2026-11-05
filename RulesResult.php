<?php

namespace App\Domain\Rules;

class RulesResult
{
    /**
     * @param array<string,mixed> $output
     * @param array<string,mixed> $context
     * @param array<string,mixed> $config
     */
    public function __construct(
        protected array $output,
        protected array $context,
        protected array $config,
    ) {
    }

    /**
     * Final projected outputs from the ruleset.
     *
     * @return array<string,mixed>
     */
    public function output(): array
    {
        return $this->output;
    }

    /**
     * Full context after rule execution.
     *
     * @return array<string,mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * Final config after rule execution.
     *
     * @return array<string,mixed>
     */
    public function config(): array
    {
        return $this->config;
    }
}
