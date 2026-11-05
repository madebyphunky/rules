<?php

namespace App\Domain\Rules\Facades;

use App\Domain\Rules\CompiledRuleset;
use App\Domain\Rules\RulesResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;

/**
 * @method static CompiledRuleset definition(array|Collection|string $definition)
 * @method static RulesResult run(array|Collection|string $definition, array|Collection $input)
 * @method static Collection runMany(array|Collection|string $definition, iterable $inputs)
 */
class PricingRules extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        // Use the pricing engine (Core + Money + Pricing)
        return 'rules.engine.pricing';
    }
}
