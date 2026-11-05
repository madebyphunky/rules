<?php

namespace App\Domain\Rules\Expressions;

use App\Domain\Rules\Contracts\RegistersExpressions;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Elegantly\Money\MoneyParser;
use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

class MoneyExpressions implements RegistersExpressions
{
    public function register(ExpressionLanguage $language): void
    {
        // money_of(amount, currency = null)
        $language->addFunction(new ExpressionFunction(
            'money_of',
            static fn ($amount, $currency = 'null') => \sprintf(
                '\Elegantly\Money\MoneyParser::parse(%s, %s ?? config("money.default_currency"))',
                $amount,
                $currency
            ),
            static function (array $vars, $amount, $currency = null): Money {
                // 1) explicit argument
                // 2) ruleset config: config.money.currency
                // 3) global config('money.default_currency', 'GBP')
                if ($currency === null) {
                    $currency = self::getConfigValue($vars, 'money.currency', 'money.default_currency', 'GBP');
                }

                return MoneyParser::parse($amount, $currency);
            },
        ));

        // money_of_minor(amount, currency = null)
        $language->addFunction(new ExpressionFunction(
            'money_of_minor',
            static fn ($minor, $currency = 'null') => \sprintf(
                '\Brick\Money\Money::ofMinor(%s, %s)',
                $minor,
                $currency
            ),
            static function (array $vars, $minor, $currency = null): Money {
                if ($currency === null) {
                    $currency = self::getConfigValue($vars, 'money.currency', 'money.default_currency', 'GBP');
                }

                return Money::ofMinor($minor, $currency);
            },
        ));

        // money_plus(a, b, rounding = null)
        $language->addFunction(new ExpressionFunction(
            'money_plus',
            static fn ($a, $b, $rounding = 'null') => \sprintf('(%s)->plus(%s, %s)', $a, $b, $rounding),
            static function (array $vars, $a, $b, $rounding = null): Money {
                [$m1, $m2] = self::normaliseMoneyPair($a, $b, $vars);
                $mode = self::mapRoundingMode($rounding, $vars);

                return $m1->plus($m2, $mode);
            },
        ));

        // money_minus(a, b, rounding = null)
        $language->addFunction(new ExpressionFunction(
            'money_minus',
            static fn ($a, $b, $rounding = 'null') => \sprintf('(%s)->minus(%s, %s)', $a, $b, $rounding),
            static function (array $vars, $a, $b, $rounding = null): Money {
                [$m1, $m2] = self::normaliseMoneyPair($a, $b, $vars);
                $mode = self::mapRoundingMode($rounding, $vars);

                return $m1->minus($m2, $mode);
            },
        ));

        // money_multiplied_by(m, factor, rounding = null)
        $language->addFunction(new ExpressionFunction(
            'money_multiplied_by',
            static fn ($m, $factor, $rounding = 'null') => \sprintf(
                '(%s)->multipliedBy(%s, %s)',
                $m,
                $factor,
                $rounding
            ),
            static function (array $vars, $m, $factor, $rounding = null): Money {
                $money = self::normaliseMoney($m, $vars);
                $mode = self::mapRoundingMode($rounding, $vars);

                return $money->multipliedBy($factor, $mode);
            },
        ));

        // money_divided_by(m, divisor, rounding = null)
        $language->addFunction(new ExpressionFunction(
            'money_divided_by',
            static fn ($m, $divisor, $rounding = 'null') => \sprintf(
                '(%s)->dividedBy(%s, %s)',
                $m,
                $divisor,
                $rounding
            ),
            static function (array $vars, $m, $divisor, $rounding = null): Money {
                $money = self::normaliseMoney($m, $vars);
                $mode = self::mapRoundingMode($rounding, $vars);

                return $money->dividedBy($divisor, $mode);
            },
        ));

        // money_between(value, min, max)
        $language->addFunction(new ExpressionFunction(
            'money_between',
            static fn ($v, $min, $max) => \sprintf(
                '((%1$s) >= (%2$s) && (%1$s) <= (%3$s))',
                $v,
                $min,
                $max
            ),
            static function (array $vars, $v, $min, $max): bool {
                $moneyV = self::normaliseMoney($v, $vars);
                $moneyMin = self::normaliseMoney($min, $vars);
                $moneyMax = self::normaliseMoney($max, $vars);

                return $moneyV->isGreaterThanOrEqualTo($moneyMin)
                    && $moneyV->isLessThanOrEqualTo($moneyMax);
            },
        ));

        // money_minor(m): int
        $language->addFunction(new ExpressionFunction(
            'money_minor',
            static fn ($m) => \sprintf('(%s)->getMinorAmount()->toInt()', $m),
            static function (array $vars, $m): int {
                $money = self::normaliseMoney($m, $vars);

                return $money->getMinorAmount()->toInt();
            },
        ));

        // money_formatted(m): string
        $language->addFunction(new ExpressionFunction(
            'money_formatted',
            static fn ($m) => \sprintf('(%s)->formatTo(config("app.locale", "en_GB"))', $m),
            static function (array $vars, $m): string {
                $money = self::normaliseMoney($m, $vars);

                // 1) ruleset config: config.money.locale
                // 2) global config('app.locale', 'en_GB')
                $locale = self::getConfigValue($vars, 'money.locale', 'app.locale', 'en_GB');

                return $money->formatTo($locale, true);
            },
        ));
    }

    public function allowedValueObjects(): array
    {
        // Brick\Money\Money is the *only* value-object we allow through this bundle
        return [
            Money::class,
        ];
    }

    /**
     * Get a config value from vars, with fallback to global config.
     *
     * @param array<string,mixed> $vars
     */
    protected static function getConfigValue(array $vars, string $key, string $globalConfigKey, mixed $default = null): mixed
    {
        $keys = \explode('.', $key);
        $value = $vars['config'] ?? null;

        foreach ($keys as $k) {
            if (!\is_array($value) || !\array_key_exists($k, $value)) {
                $value = null;
                break;
            }
            $value = $value[$k];
        }

        if ($value !== null) {
            return $value;
        }

        if ($globalConfigKey !== '') {
            return config($globalConfigKey, $default);
        }

        return $default;
    }

    /**
     * Merge a config value into vars, preserving existing config structure.
     *
     * @param array<string,mixed> $vars Context variables
     * @param string $key Config key path (e.g., 'money.currency')
     * @param mixed $value Value to set
     *
     * @return array<string,mixed> New vars array with merged config
     */
    protected static function mergeConfig(array $vars, string $key, mixed $value): array
    {
        $merged = $vars;

        if (!isset($merged['config'])) {
            $merged['config'] = [];
        }

        $keys = \explode('.', $key);
        $current = &$merged['config'];

        foreach ($keys as $k) {
            if (!isset($current[$k]) || !\is_array($current[$k])) {
                $current[$k] = [];
            }
            $current = &$current[$k];
        }

        $current = $value;

        return $merged;
    }

    /**
     * Normalise a value to Money, using ruleset config.currency when available.
     *
     * @param array<string,mixed> $vars
     */
    protected static function normaliseMoney(mixed $value, array $vars): Money
    {
        if ($value instanceof Money) {
            return $value;
        }

        $currency = self::getConfigValue($vars, 'money.currency', 'money.default_currency', 'GBP');

        return MoneyParser::parse($value, $currency);
    }

    /**
     * Normalize two values to Money instances, ensuring currency compatibility.
     *
     * When one value is already a Money instance, its currency is used for the other.
     * When both are scalars, uses ruleset config or default currency.
     *
     * @param array<string,mixed> $vars
     *
     * @return array{0: \Brick\Money\Money, 1: \Brick\Money\Money}
     *
     * @throws \InvalidArgumentException
     */
    protected static function normaliseMoneyPair(mixed $a, mixed $b, array $vars): array
    {
        if ($a instanceof Money && $b instanceof Money) {
            if (!$a->getCurrency()->is($b->getCurrency())) {
                throw new \InvalidArgumentException(
                    \sprintf(
                        'Currency mismatch in money operation: %s vs %s.',
                        $a->getCurrency()->getCurrencyCode(),
                        $b->getCurrency()->getCurrencyCode()
                    )
                );
            }

            return [$a, $b];
        }

        if ($a instanceof Money) {
            $currency = $a->getCurrency()->getCurrencyCode();
            $mergedVars = self::mergeConfig($vars, 'money.currency', $currency);

            return [
                $a,
                self::normaliseMoney($b, $mergedVars),
            ];
        }

        if ($b instanceof Money) {
            $currency = $b->getCurrency()->getCurrencyCode();
            $mergedVars = self::mergeConfig($vars, 'money.currency', $currency);

            return [
                self::normaliseMoney($a, $mergedVars),
                $b,
            ];
        }

        // Both scalars → use ruleset currency or default
        $currency = self::getConfigValue($vars, 'money.currency', 'money.default_currency', 'GBP');

        return [
            MoneyParser::parse($a, $currency),
            MoneyParser::parse($b, $currency),
        ];
    }

    /**
     * Map string/null to Brick RoundingMode, using ruleset config if we want later.
     *
     * @param array<string,mixed> $vars
     */
    protected static function mapRoundingMode(?string $rounding, array $vars = []): RoundingMode
    {
        if ($rounding === null) {
            // Check ruleset config first, then default to 'DOWN'
            $rounding = self::getConfigValue($vars, 'money.rounding_mode', '', 'DOWN');
        }

        return match (\strtoupper($rounding)) {
            'UP' => RoundingMode::UP,
            'DOWN' => RoundingMode::DOWN,
            'CEILING' => RoundingMode::CEILING,
            'FLOOR' => RoundingMode::FLOOR,
            'HALF_UP' => RoundingMode::HALF_UP,
            'HALF_DOWN' => RoundingMode::HALF_DOWN,
            'HALF_EVEN' => RoundingMode::HALF_EVEN,
            'UNNECESSARY' => RoundingMode::UNNECESSARY,
            default => RoundingMode::DOWN,
        };
    }
}
