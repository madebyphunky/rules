<?php

namespace App\Domain\Rules\Expressions;

use App\Domain\Rules\Contracts\RegistersExpressions;
use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

class PricingExpressions implements RegistersExpressions
{
    public function register(ExpressionLanguage $language): void
    {
        // band_for(value, bands): array|null
        //
        // bands is expected to be an array of arrays with at least:
        //   - min (float|int)
        //   - max (float|int|null)
        //   - provider_code
        //   - premium_delta
        $language->addFunction(new ExpressionFunction(
            'band_for',
            static fn ($value, $bands) => \sprintf(
                '\%s::bandFor(%s, %s)',
                self::class,
                $value,
                $bands
            ),
            static function (array $vars, $value, $bands): ?array {
                return self::bandFor($value, $bands);
            },
        ));
    }

    public function allowedValueObjects(): array
    {
        return [];
    }

    /**
     * Find the band that matches the given value.
     *
     * @param iterable<array{min: float|int, max?: float|int|null, ...}> $bands
     *
     * @return array{min: float|int, max?: float|int|null, ...}|null
     */
    public static function bandFor(float|int $value, iterable $bands): ?array
    {
        foreach ($bands as $band) {
            if (! is_array($band)) {
                continue;
            }

            $min = $band['min'] ?? null;
            $max = $band['max'] ?? null;

            if ($min === null) {
                continue;
            }

            if ($value < $min) {
                continue;
            }

            if ($max !== null && $value > $max) {
                continue;
            }

            return $band;
        }

        return null;
    }
}
