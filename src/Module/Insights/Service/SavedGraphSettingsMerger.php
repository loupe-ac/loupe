<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Bridge\Metric\Formula\MetricFormula;
use App\Module\Insights\Entity\InsightsSavedGraph;

/** Puts the settings that a change passes on top of the settings that a graph keeps. */
final class SavedGraphSettingsMerger
{
    /**
     * Checks that the settings of a new graph name one metric or a formula, and not both.
     *
     * @param array<string, string> $settings the settings of the save, without the ones it left out
     *
     * @throws SavedGraphChangeRefused when the settings name both, or neither
     */
    public static function checkNew(array $settings): void
    {
        $isFormula = isset($settings['rows']) || isset($settings['formula']) || isset($settings['format']);
        if ($isFormula && (isset($settings['metric']) || isset($settings['statistic']))) {
            throw new SavedGraphChangeRefused(['metric', 'statistic'], 'Pass metric and statistic, or rows and formula, and not both.', false);
        }
        if (!$isFormula && (!isset($settings['metric']) || !isset($settings['statistic']))) {
            throw new SavedGraphChangeRefused(['metric', 'statistic'], 'Pass metric and statistic to save one metric, or rows and formula to save a formula.', false);
        }
    }

    /**
     * @param array<string, string> $passed        the one-metric settings that the change passed
     * @param array<string, string> $formulaPassed the rows, the formula and the format that the change passed
     *
     * @return array<string, string>
     *
     * @throws SavedGraphChangeRefused when the change mixes one metric with a formula
     */
    public static function merge(InsightsSavedGraph $graph, array $passed, array $formulaPassed, bool $clear): array
    {
        $isFormula = null !== $graph->formulaRows;
        $barePassed = isset($passed['metric']) || isset($passed['statistic']);
        if ($clear) {
            if ([] !== $formulaPassed) {
                throw new SavedGraphChangeRefused(['clearFormula'], 'clearFormula cannot go with rows, formula or format. Pass clearFormula alone to go back to one metric.', false);
            }
            if (!$isFormula) {
                throw new SavedGraphChangeRefused(['clearFormula'], 'This graph reads one metric, so it has no formula to clear.');
            }

            return array_diff_key($passed + $graph->settings(), ['rows' => true, 'formula' => true, 'format' => true]);
        }
        if ($isFormula && $barePassed && [] === $formulaPassed) {
            throw new SavedGraphChangeRefused(['metric', 'statistic'], 'This graph reads a formula, and its metric and statistic are its first row. Pass rows to change the rows, or clearFormula true to go back to one metric.');
        }
        if (!$isFormula && [] !== $formulaPassed && !isset($formulaPassed['rows'], $formulaPassed['formula'])) {
            throw new SavedGraphChangeRefused(['rows', 'formula'], 'To turn this graph into a formula graph, pass both rows and formula.');
        }

        $merged = $passed + $formulaPassed + $graph->settings();
        if ($barePassed && isset($merged['rows'])) {
            $first = MetricFormula::rowParts($merged['rows'])[0] ?? null;
            if (null === $first || (isset($passed['metric']) && $passed['metric'] !== $first['metric']) || (isset($passed['statistic']) && $passed['statistic'] !== $first['statistic'])) {
                throw new SavedGraphChangeRefused(['metric', 'statistic'], 'With rows, leave metric and statistic out, or pass the first row: they are the first row of the formula.');
            }
        }

        return $merged;
    }
}
