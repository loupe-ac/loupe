<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Metric\MetricChangeKind;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricValueType;

/** A metric value as a person reads it. Null for an unknown value, which the template translates. */
final readonly class MetricValueFormatter
{
    public function __construct(
        private string $locale,
    ) {
    }

    /**
     * A count of rows reads as a number, whatever the type of its metric. A bare value type
     * stands for a built-in metric, and a template duration is in hours.
     *
     * @param ?MetricStatistic $statistic     null for the value of one row
     * @param ?int             $moneyDecimals the decimals of a dollar amount, two when null
     */
    public function format(int|float|null $value, MetricShape|MetricValueType $metric, ?MetricStatistic $statistic = null, ?int $moneyDecimals = null): ?string
    {
        if (null === $value) {
            return null;
        }
        if (MetricStatistic::Count === $statistic) {
            return $this->number($value, \NumberFormatter::DECIMAL, 0, 0);
        }

        $inHours = $metric instanceof MetricShape && $metric->isInHours();
        $type = $metric instanceof MetricShape ? $metric->valueType() : $metric;

        return match ($type) {
            MetricValueType::Money => null !== $moneyDecimals ? $this->money($value, $moneyDecimals, $moneyDecimals) : $this->money($value, 2, self::maxMoneyDecimals($value)),
            MetricValueType::Ratio => $this->number($value, \NumberFormatter::PERCENT, 1, 1),
            MetricValueType::Percent => $this->number($value, \NumberFormatter::DECIMAL, 0, 1).' %',
            MetricValueType::Duration => $inHours
                ? $this->number($value, \NumberFormatter::DECIMAL, 0, 1).' h'
                : self::milliseconds((int) round($value)),
            MetricValueType::Tokens, MetricValueType::Count, MetricValueType::Boolean => $this->number($value, \NumberFormatter::DECIMAL, 0, 1),
        };
    }

    /** A fraction of a whole as a percentage, with a decimal only when it has one. */
    public function share(float $fraction): string
    {
        return $this->number($fraction, \NumberFormatter::PERCENT, 0, 1);
    }

    /**
     * The size of a change with no sign or arrow: a percentage for a relative change, points for a change of
     * rates, and the unit of the metric for an absolute change. The caller adds the direction.
     */
    public function change(float $change, MetricChangeKind $kind, MetricShape $metric, ?MetricStatistic $statistic): string
    {
        $size = abs($change);

        return match ($kind) {
            MetricChangeKind::Relative => $this->number($size, \NumberFormatter::PERCENT, 0, self::relativeDecimals($size)),
            MetricChangeKind::Points => $this->number($size * 100, \NumberFormatter::DECIMAL, 0, 1),
            MetricChangeKind::Absolute => $this->format($size, $metric, $statistic) ?? '',
        };
    }

    /** True when change() would print a size of zero, so no arrow should show. */
    public function changeIsNothing(float $change, MetricChangeKind $kind): bool
    {
        $size = abs($change);

        return match ($kind) {
            MetricChangeKind::Relative => 0.0 === round($size * 100, self::relativeDecimals($size)),
            MetricChangeKind::Points => 0.0 === round($size * 100, 1),
            MetricChangeKind::Absolute => 0.0 === $size,
        };
    }

    private static function relativeDecimals(float $size): int
    {
        return $size < 0.1 ? 1 : 0;
    }

    /** A cost under half a cent keeps its micro-dollars, so it never reads as free. */
    private static function maxMoneyDecimals(int|float $value): int
    {
        return $value > 0 && $value < 0.005 ? 6 : 2;
    }

    private function money(int|float $value, int $minDecimals, int $maxDecimals): string
    {
        $formatter = new \NumberFormatter($this->locale, \NumberFormatter::CURRENCY);
        $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $minDecimals);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $maxDecimals);

        return (string) $formatter->formatCurrency((float) $value, 'USD');
    }

    private function number(int|float $value, int $style, int $minDecimals, int $maxDecimals): string
    {
        $formatter = new \NumberFormatter($this->locale, $style);
        $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $minDecimals);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $maxDecimals);

        return (string) $formatter->format($value);
    }

    private static function milliseconds(int $milliseconds): string
    {
        if ($milliseconds < 1000) {
            return $milliseconds.' ms';
        }

        $seconds = intdiv($milliseconds + 500, 1000);
        if ($seconds < 60) {
            return $seconds.' s';
        }
        if ($seconds < 3600) {
            return self::twoParts(intdiv($seconds, 60), 'min', $seconds % 60, 's');
        }

        return self::twoParts(intdiv($seconds, 3600), 'h', intdiv($seconds % 3600, 60), 'min');
    }

    private static function twoParts(int $whole, string $wholeUnit, int $rest, string $restUnit): string
    {
        return 0 === $rest ? $whole.' '.$wholeUnit : $whole.' '.$wholeUnit.' '.$rest.' '.$restUnit;
    }
}
