<?php

namespace App\Domain\Rules;

use App\Domain\Rules\Expressions\CoreExpressions;
use App\Domain\Rules\Expressions\MoneyExpressions;
use App\Domain\Rules\Expressions\PricingExpressions;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

class RulesEngineFactory
{
    public function __construct(
        protected CoreExpressions $coreExpressions,
        protected MoneyExpressions $moneyExpressions,
        protected PricingExpressions $pricingExpressions,
    ) {
    }

    /**
     * Core engine: core + money expressions.
     */
    public function core(): RulesEngine
    {
        $language = new ExpressionLanguage();

        $bundles = [
            $this->coreExpressions,
            $this->moneyExpressions,
        ];

        $allowed = [];

        foreach ($bundles as $bundle) {
            $bundle->register($language);

            // New: collect value-object allow-list from bundles (if implemented)
            if (method_exists($bundle, 'allowedValueObjects')) {
                $allowed = array_merge($allowed, (array) $bundle->allowedValueObjects());
            }
        }

        $allowed = array_values(array_unique($allowed));

        return new RulesEngine($language, $allowed);
    }

    /**
     * Pricing engine: core + money + pricing domain helpers.
     */
    public function pricing(): RulesEngine
    {
        $language = new ExpressionLanguage();

        $bundles = [
            $this->coreExpressions,
            $this->moneyExpressions,
            $this->pricingExpressions,
        ];

        $allowed = [];

        foreach ($bundles as $bundle) {
            $bundle->register($language);

            if (method_exists($bundle, 'allowedValueObjects')) {
                $allowed = array_merge($allowed, (array) $bundle->allowedValueObjects());
            }
        }

        $allowed = array_values(array_unique($allowed));

        return new RulesEngine($language, $allowed);
    }
}
