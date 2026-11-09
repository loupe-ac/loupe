<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\View;

use App\Module\Bridge\Metric\Formula\MetricFormula;
use App\Module\Bridge\Metric\MetricPoint;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Insights\View\FormulaNote;
use App\Module\Insights\View\FormulaNotes;
use App\Module\Workflow\Contract\TemplateMetricSet;
use PHPUnit\Framework\TestCase;

final class FormulaNotesTest extends TestCase
{
    public function test_a_one_metric_graph_and_a_formula_with_a_problem_have_no_notes(): void
    {
        self::assertSame([], FormulaNotes::of(null, []));
        self::assertSame([], FormulaNotes::of(self::formula('A:cost:sum', 'A / (', 'auto'), []));
    }

    public function test_a_clean_formula_has_no_notes(): void
    {
        self::assertSame([], FormulaNotes::of(self::formula('A:cache-read-tokens:sum,B:cache-write-tokens:sum', 'A / B', 'auto'), [self::series(2.0)]));
    }

    public function test_percent_always_disagrees_with_auto_and_a_point_above_one_hundred_percent_adds_a_note(): void
    {
        $formula = self::formula('A:cache-read-tokens:sum,B:cache-write-tokens:sum', 'A / B', 'percent');

        self::assertSame([FormulaNote::FORMAT_DISAGREES], self::keys(FormulaNotes::of($formula, [self::series(0.5)])));
        self::assertSame([FormulaNote::PERCENT_ABOVE_ONE_HUNDRED, FormulaNote::FORMAT_DISAGREES], self::keys(FormulaNotes::of($formula, [self::series(2.0)])));
    }

    public function test_number_on_cost_per_wake_disagrees_because_auto_gives_money(): void
    {
        $formula = self::formula('A:cost:sum,B:wakes:sum', 'A / B', 'number');

        self::assertSame([FormulaNote::FORMAT_DISAGREES], self::keys(FormulaNotes::of($formula, [])));
    }

    public function test_a_row_that_the_formula_does_not_use_is_named(): void
    {
        $notes = FormulaNotes::of(self::formula('A:cache-read-tokens:sum,B:cache-write-tokens:sum,C:input-tokens:sum', 'A / B', 'auto'), []);

        self::assertSame([FormulaNote::UNUSED_ROW], self::keys($notes));
        self::assertSame(['%letters%' => 'C'], $notes[0]->parameters);
    }

    private static function formula(string $rows, string $formula, string $format): MetricFormula
    {
        return MetricFormula::parse($rows, $formula, $format, new TemplateMetricSet([]));
    }

    private static function series(float $value): MetricSeries
    {
        $point = new MetricPoint(new \DateTimeImmutable('2026-09-01'), $value, 1);

        return new MetricSeries(null, [$point], new MetricPoint(null, $value, 1), []);
    }

    /**
     * @param list<FormulaNote> $notes
     *
     * @return list<string>
     */
    private static function keys(array $notes): array
    {
        return array_map(static fn (FormulaNote $note): string => $note->key, $notes);
    }
}
