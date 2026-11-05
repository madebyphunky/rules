<?php

namespace App\Domain\Rules\Providers;

use App\Domain\Rules\RulesEngine;
use App\Domain\Rules\RulesEngineFactory;
use Illuminate\Support\ServiceProvider;

class RulesEngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 1) Factory itself: let Laravel resolve the dependencies
        //    (CoreExpressions, MoneyExpressions, PricingExpressions) via the constructor.
        $this->app->singleton(RulesEngineFactory::class);

        // 2) Core engine binding (default RulesEngine)
        $this->app->singleton('rules.engine.core', function ($app) {
            /** @var \App\Domain\Rules\RulesEngineFactory $factory */
            $factory = $app->make(RulesEngineFactory::class);

            return $factory->core();
        });

        // Optional: type-hinting RulesEngine resolves the core engine
        $this->app->alias('rules.engine.core', RulesEngine::class);

        // 3) Pricing engine binding
        $this->app->singleton('rules.engine.pricing', function ($app) {
            /** @var \App\Domain\Rules\RulesEngineFactory $factory */
            $factory = $app->make(RulesEngineFactory::class);

            return $factory->pricing();
        });
    }
}
