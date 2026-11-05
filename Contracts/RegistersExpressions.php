<?php

namespace App\Domain\Rules\Contracts;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

interface RegistersExpressions
{
    /**
     * Register functions on the ExpressionLanguage instance.
     */
    public function register(ExpressionLanguage $language): void;

    /**
     * List of value object classes that this expression bundle allows
     * to flow into ExpressionLanguage as-is.
     *
     * @return class-string[]
     */
    public function allowedValueObjects(): array;
}
