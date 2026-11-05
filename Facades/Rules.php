<?php

namespace App\Domain\Rules\Facades;

use App\Domain\Rules\RulesEngine;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \App\Domain\Rules\CompiledRuleset definition(array|\Illuminate\Support\Collection|string $definition)
 * @method static \App\Domain\Rules\RulesResult run(array|\Illuminate\Support\Collection|string $definition, array|\Illuminate\Support\Collection $input)
 * @method static \Illuminate\Support\Collection runMany(array|\Illuminate\Support\Collection|string $definition, iterable $inputs)
 *
 * @mixin \App\Domain\Rules\RulesEngine
 */
class Rules extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        // We bound RulesEngine::class as a singleton in the service provider,
        // so we can just return the class name here.
        return RulesEngine::class;
    }
}
