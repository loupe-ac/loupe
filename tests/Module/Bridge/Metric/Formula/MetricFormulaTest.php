<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Metric\Formula;

use App\Module\Bridge\Metric\Formula\FormulaFormat;
use App\Module\Bridge\Metric\Formula\FormulaProblem;
use App\Module\Bridge\Metric\Formula\MetricFormula;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Bridge\Metric\MetricValueType;
use App\Module\Workflow\Contract\TemplateMetricSet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MetricFormulaTest extends TestCase
{
    private const string TOKENS = 'A:cache-read-tokens:sum,B:cache-write-tokens:sum';
    private const string MIXED = 'A:cost:sum,B:wakes:sum,C:cache-read-tokens:sum,D:runs:sum';

    private static function formula(string $rows, string $formula, string $format = 'auto'): MetricFormula
    {
        return MetricFormula::parse($rows, $formula, $format, new TemplateMetricSet());
    }

    public function test_a_token_ratio_is_valid_and_shows_as_a_number(): void
    {
        $formula = self::formula(self::TOKENS, 'A / B');

        self::assertNull($formula->problem);
        self::assertSame(MetricValueType::Number, $formula->autoType);
        self::assertSame(MetricValueType::Number, $formula->valueType());
        self::assertSame(self::TOKENS, $formula->rowsText());
        self::assertSame('A', $formula->earliestRow()?->letter);
    }

    /** @return iterable<string, array{string, string, MetricValueType}> */
    public static function autoTypes(): iterable
    {
        yield 'money over a count stays money' => [self::MIXED, 'A / B', MetricValueType::Money];
        yield 'money over a number stays money' => [self::MIXED, 'A / 2', MetricValueType::Money];
        yield 'a count over money is a number' => [self::MIXED, 'B / A', MetricValueType::Number];
        yield 'one over money is a number' => [self::MIXED, '1 / A', MetricValueType::Number];
        yield 'two equal types give a number' => [self::MIXED, 'C / C', MetricValueType::Number];
        yield 'a count over a count is a number' => [self::MIXED, 'B / D', MetricValueType::Number];
        yield 'money times a count stays money' => [self::MIXED, 'A * B', MetricValueType::Money];
        yield 'a count times money is money' => [self::MIXED, 'B * A', MetricValueType::Money];
        yield 'money times a number stays money' => [self::MIXED, '2 * A', MetricValueType::Money];
        yield 'money times tokens is a number' => [self::MIXED, 'A * C', MetricValueType::Number];
        yield 'a sum of equal types keeps the type' => [self::MIXED, 'A + A', MetricValueType::Money];
        yield 'a sum with a plain number keeps the type' => [self::MIXED, 'A - 1', MetricValueType::Money];
        yield 'a sign keeps the type' => [self::MIXED, '-A', MetricValueType::Money];
        yield 'a plain number is a number' => [self::MIXED, '1 + 2', MetricValueType::Number];
        yield 'tokens stay a number' => [self::MIXED, 'C', MetricValueType::Number];
        yield 'a count stays a number' => [self::MIXED, 'B', MetricValueType::Number];
    }

    #[DataProvider('autoTypes')]
    public function test_auto_follows_the_type_rules(string $rows, string $formula, MetricValueType $expected): void
    {
        $parsed = self::formula($rows, $formula);

        self::assertNull($parsed->problem);
        self::assertSame($expected, $parsed->autoType);
    }

    public function test_a_duration_row_gives_a_duration(): void
    {
        $parsed = self::formula('A:duration:sum,B:runs:sum', 'A / B');

        self::assertSame(MetricValueType::Duration, $parsed->autoType);
    }

    public function test_a_row_on_the_count_statistic_is_a_count(): void
    {
        $parsed = self::formula('A:cost:count,B:cost:sum', 'B / A');

        self::assertSame(MetricValueType::Money, $parsed->autoType);
    }

    public function test_the_chosen_format_wins_over_auto(): void
    {
        self::assertSame(MetricValueType::Fraction, self::formula(self::TOKENS, 'A / B', 'percent')->valueType());
        self::assertSame(MetricValueType::Number, self::formula(self::MIXED, 'A / B', 'number')->valueType());
        self::assertSame(MetricValueType::Money, self::formula(self::MIXED, 'A / B', 'money')->valueType());
        self::assertSame(MetricValueType::Duration, self::formula(self::TOKENS, 'A / B', 'duration')->valueType());
        self::assertSame(FormulaFormat::Percent, self::formula(self::TOKENS, 'A / B', 'percent')->format);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function problems(): iterable
    {
        yield 'money plus tokens' => [self::MIXED, 'A + C', 'auto', FormulaProblem::MIXED_TYPES];
        yield 'a letter with no row' => [self::TOKENS, 'A / C', 'auto', FormulaProblem::UNKNOWN_LETTER];
        yield 'a syntax mistake' => [self::TOKENS, 'A / (B', 'auto', FormulaProblem::UNCLOSED_PARENTHESIS];
        yield 'no rows' => ['', 'A', 'auto', FormulaProblem::NO_ROWS];
        yield 'seven rows' => ['A:runs:sum,B:runs:sum,C:runs:sum,D:runs:sum,E:runs:sum,F:runs:sum,G:runs:sum', 'A', 'auto', FormulaProblem::TOO_MANY_ROWS];
        yield 'a letter twice' => ['A:runs:sum,A:cost:sum', 'A', 'auto', FormulaProblem::DUPLICATE_LETTER];
        yield 'a letter past F' => ['G:runs:sum', 'G', 'auto', FormulaProblem::BAD_ROW];
        yield 'a row with one colon' => ['A:runs', 'A', 'auto', FormulaProblem::BAD_ROW];
        yield 'an unknown metric' => ['A:nothing:sum', 'A', 'auto', FormulaProblem::UNKNOWN_METRIC];
        yield 'a metric that is not additive' => ['A:stop-rate:mean', 'A', 'auto', FormulaProblem::METRIC_CANNOT_JOIN];
        yield 'a grouped bucket metric' => ['A:bucket-time-each:sum', 'A', 'auto', FormulaProblem::METRIC_CANNOT_JOIN];
        yield 'a statistic the metric lacks' => ['A:runs:max', 'A', 'auto', FormulaProblem::STATISTIC_NOT_ALLOWED];
        yield 'an unknown statistic' => ['A:runs:total', 'A', 'auto', FormulaProblem::STATISTIC_NOT_ALLOWED];
        yield 'a syntax error with a chosen format' => [self::TOKENS, 'A / (B', 'percent', FormulaProblem::UNCLOSED_PARENTHESIS];
        yield 'an unknown metric with a chosen format' => ['A:nothing:sum', 'A', 'money', FormulaProblem::UNKNOWN_METRIC];
        yield 'an unknown format' => [self::TOKENS, 'A / B', 'percentage', FormulaProblem::UNKNOWN_FORMAT];
    }

    #[DataProvider('problems')]
    public function test_a_problem_gives_a_formula_with_no_type(string $rows, string $formula, string $format, string $key): void
    {
        $parsed = self::formula($rows, $formula, $format);

        self::assertSame($key, $parsed->problem?->key);
        self::assertNull($parsed->valueType());
        self::assertNull($parsed->tree);
    }

    public function test_letters_need_not_be_contiguous(): void
    {
        $parsed = self::formula('A:cache-read-tokens:sum,D:cache-write-tokens:sum', 'A / D');

        self::assertNull($parsed->problem);
        self::assertSame(['A', 'D'], array_map(static fn ($row): string => $row->letter, $parsed->rows));
    }

    public function test_a_named_metric_key_with_a_colon_splits_at_the_first_and_the_last_colon(): void
    {
        $parsed = self::formula('A:bucket-time:tests:sum,B:runs:sum', 'A / B');

        self::assertNull($parsed->problem);
        self::assertSame('bucket-time:tests', $parsed->rows[0]->shape->key->key());
        self::assertSame('A:bucket-time:tests:sum,B:runs:sum', $parsed->rowsText());
    }

    public function test_a_template_metric_cannot_join(): void
    {
        $templates = new TemplateMetricSet(metrics: [\App\Tests\Support\TemplateMetricSets::count()]);

        $parsed = MetricFormula::parse('A:fix-rounds:sum', 'A', 'auto', $templates);

        self::assertSame(FormulaProblem::METRIC_CANNOT_JOIN, $parsed->problem?->key);
    }

    public function test_the_units_and_the_groups_are_the_ones_every_row_allows(): void
    {
        $tokens = self::formula(self::TOKENS, 'A / B');
        self::assertSame([MetricUnit::Run, MetricUnit::Card], $tokens->units());
        self::assertContains(MetricGroup::Model, $tokens->groups());
        self::assertNotContains(MetricGroup::WakeGap, $tokens->groups());

        $runs = self::formula('A:cost:sum,B:runs:sum', 'A / B');
        self::assertSame([MetricUnit::Card], $runs->units());

        $wakes = self::formula('A:wakes:sum,B:cold-wakes:sum', 'B / A');
        self::assertContains(MetricGroup::WakeGap, $wakes->groups());
        self::assertContains(MetricGroup::WakeCache, $wakes->groups());

        $mixed = self::formula('A:wakes:sum,B:cost:sum', 'B / A');
        self::assertNotContains(MetricGroup::WakeGap, $mixed->groups());
        self::assertContains(MetricGroup::None, $mixed->groups());
    }

    public function test_a_formula_with_no_rows_allows_nothing(): void
    {
        $formula = self::formula('', 'A');

        self::assertSame([], $formula->units());
        self::assertSame([], $formula->groups());
    }
}
