<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Metric\Formula\FormulaFormat;
use App\Module\Bridge\Metric\Formula\MetricFormula;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricValueType;

/** The notes of a formula graph. Only the Metrics tab shows them. */
final class FormulaNotes
{
    /**
     * @param list<MetricSeries> $series
     *
     * @return list<FormulaNote>
     */
    public static function of(?MetricFormula $formula, array $series): array
    {
        if (null === $formula || null !== $formula->problem) {
            return [];
        }

        $notes = [];
        if (MetricValueType::Fraction === $formula->valueType() && self::hasPointAboveOne($series)) {
            $notes[] = new FormulaNote(FormulaNote::PERCENT_ABOVE_ONE_HUNDRED);
        }
        if (FormulaFormat::Auto !== $formula->format && $formula->format->valueType() !== $formula->autoType) {
            $notes[] = new FormulaNote(FormulaNote::FORMAT_DISAGREES);
        }
        $unused = $formula->unusedLetters();
        if ([] !== $unused) {
            $notes[] = new FormulaNote(FormulaNote::UNUSED_ROW, ['%letters%' => implode(', ', $unused)]);
        }

        return $notes;
    }

    /** @param list<MetricSeries> $series */
    private static function hasPointAboveOne(array $series): bool
    {
        return array_any($series, static fn (MetricSeries $line): bool => array_any($line->points, static fn ($point): bool => null !== $point->value && $point->value > 1));
    }
}
