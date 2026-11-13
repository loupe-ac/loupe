<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Bridge\Metric\Formula\MetricFormula;
use App\Module\Insights\Command\ListSavedGraphsView;
use App\Module\Insights\Entity\InsightsSavedGraph;

/**
 * @phpstan-type FormulaPayload array{rows: list<array{letter: string, metric: string, statistic: string}>, formula: string, format: string}
 */
final class SavedGraphShape
{
    /**
     * The one-metric controls of a graph. The rows, the formula and the format of a formula graph come as formula.
     *
     * @return array<string, string>
     */
    public static function settingsOf(InsightsSavedGraph $graph): array
    {
        return array_diff_key($graph->settings(), ['rows' => true, 'formula' => true, 'format' => true]);
    }

    /** @return ?FormulaPayload null for a graph that reads one metric */
    public static function formulaOf(InsightsSavedGraph $graph): ?array
    {
        if (null === $graph->formulaRows) {
            return null;
        }

        return ['rows' => MetricFormula::rowParts($graph->formulaRows), 'formula' => $graph->formula ?? '', 'format' => $graph->format ?? ''];
    }

    /** The place of a graph among the graphs on show, counting from 0, or null when it is not on show. */
    public static function positionOf(ListSavedGraphsView $view, InsightsSavedGraph $graph): ?int
    {
        return array_find_key($view->graphs, static fn (InsightsSavedGraph $other): bool => $other->id?->equals($graph->id) ?? false);
    }
}
