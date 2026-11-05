<?php

namespace App\Domain\Rules\Expressions;

use App\Domain\Rules\Contracts\RegistersExpressions;
use Illuminate\Support\Arr;
use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

class CoreExpressions implements RegistersExpressions
{
    public function register(ExpressionLanguage $language): void
    {
        // coalesce(a, b) => a ?? b
        $language->addFunction(new ExpressionFunction(
            'coalesce',
            static fn ($a, $b) => \sprintf('(%s) ?? (%s)', $a, $b),
            static fn (array $vars, $a, $b) => $a ?? $b,
        ));

        // in_array(needle, haystack)
        $language->addFunction(new ExpressionFunction(
            'in_array',
            static fn ($needle, $haystack) => \sprintf('in_array(%s, %s, true)', $needle, $haystack),
            static function (array $vars, $needle, $haystack): bool {
                if (! \is_array($haystack)) {
                    return false;
                }

                return \in_array($needle, $haystack, true);
            },
        ));

        // clamp(value, min, max)
        $language->addFunction(new ExpressionFunction(
            'clamp',
            static fn ($v, $min, $max) => \sprintf(
                'max(min(%s, %s), %s)',
                $v,
                $max,
                $min
            ),
            static function (array $vars, $value, $min, $max): float|int {
                if ($value < $min) {
                    return $min;
                }
                if ($value > $max) {
                    return $max;
                }

                return $value;
            },
        ));

        // lowercase(string)
        $language->addFunction(new ExpressionFunction(
            'lowercase',
            static fn ($str) => \sprintf('(is_string(%1$s) ? strtolower(%1$s) : %1$s)', $str),
            static function (array $vars, $str): mixed {
                return \is_string($str) ? \strtolower($str) : $str;
            },
        ));

        // uppercase(string)
        $language->addFunction(new ExpressionFunction(
            'uppercase',
            static fn ($str) => \sprintf('(is_string(%1$s) ? strtoupper(%1$s) : %1$s)', $str),
            static function (array $vars, $str): mixed {
                return \is_string($str) ? \strtoupper($str) : $str;
            },
        ));

        // empty(value): bool
        $language->addFunction(new ExpressionFunction(
            'is_empty',
            static fn ($v) => \sprintf('empty(%s)', $v),
            static function (array $vars, $v): bool {
                return empty($v);
            },
        ));

        // get(path, default = null)
        $language->addFunction(new ExpressionFunction(
            'get',
            // compile-time stub (we only really use evaluate())
            static fn ($path, $default = 'null') => 'null',
            // runtime
            static function (array $vars, string $path, mixed $default = null): mixed {
                return Arr::get($vars, $path, $default);
            },
        ));
    }

    public function allowedValueObjects(): array
    {
        // Core expressions don't introduce any value objects
        return [];
    }
}
