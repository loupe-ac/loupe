<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Experiment;

use App\Module\Bridge\Experiment\ControlEvaluator;
use App\Module\Bridge\Experiment\ControlResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ControlEvaluatorTest extends TestCase
{
    /** @return array<string, array{string, int|float, bool}> */
    public static function expectations(): array
    {
        return [
            'greater, above' => ['> 0', 1, true],
            'greater, equal' => ['> 0', 0, false],
            'greater or equal, equal' => ['>= 3', 3, true],
            'greater or equal, below' => ['>=3', 2, false],
            'less, below' => ['< 5', 4.5, true],
            'less, equal' => ['< 5', 5, false],
            'less or equal, equal' => ['<= 5', 5, true],
            'equal, same' => ['== 0', 0, true],
            'equal, other' => ['== 0', 1, false],
            'negative limit' => ['> -1', 0, true],
            'decimal limit' => ['>= 0.5', 0.5, true],
        ];
    }

    #[DataProvider('expectations')]
    public function test_a_card_meets_a_comparison(string $expect, int|float $value, bool $passes): void
    {
        $controls = ControlEvaluator::evaluate(
            [['metric' => 'cost', 'variant' => 'a', 'expect' => $expect, 'share' => 1.0]],
            ['cost' => ['a' => [$value]]],
            ['a' => 1],
        );

        self::assertSame($passes ? ControlResult::Passed : ControlResult::Failed, $controls[0]->result);
    }

    public function test_a_control_passes_when_the_share_of_passing_cards_reaches_its_share(): void
    {
        $spec = ['metric' => 'harness-events:diagnostics', 'variant' => 'lsp', 'expect' => '> 0', 'share' => 0.5];

        $exact = ControlEvaluator::evaluate([$spec], ['harness-events:diagnostics' => ['lsp' => [1, 0]]], ['lsp' => 4])[0];
        $below = ControlEvaluator::evaluate([$spec], ['harness-events:diagnostics' => ['lsp' => [1, 0, 0]]], ['lsp' => 4])[0];

        self::assertSame(ControlResult::Passed, $exact->result);
        self::assertSame(0.5, $exact->passingShare);
        self::assertSame(2, $exact->cardsWithData);
        self::assertSame(4, $exact->cardsFinished);
        self::assertSame(ControlResult::Failed, $below->result);
        self::assertEqualsWithDelta(1 / 3, $below->passingShare, 1e-9);
    }

    public function test_a_variant_with_no_value_has_no_data(): void
    {
        $controls = ControlEvaluator::evaluate(
            [['metric' => 'harness-events:diagnostics', 'variant' => 'lsp', 'expect' => '> 0', 'share' => 0.5]],
            ['harness-events:diagnostics' => ['other' => [1]]],
            ['lsp' => 3],
        );

        self::assertSame(ControlResult::NoData, $controls[0]->result);
        self::assertNull($controls[0]->passingShare);
        self::assertSame(0, $controls[0]->cardsWithData);
        self::assertSame(3, $controls[0]->cardsFinished);
    }

    public function test_a_key_with_no_metric_is_unknown(): void
    {
        $controls = ControlEvaluator::evaluate(
            [['metric' => 'bucket-calls:lps', 'variant' => 'lsp', 'expect' => '> 0', 'share' => 0.5]],
            ['bucket-calls:lps' => null],
            ['lsp' => 3],
        );

        self::assertSame(ControlResult::UnknownMetric, $controls[0]->result);
        self::assertNull($controls[0]->passingShare);
    }

    public function test_a_template_label_travels_with_the_control(): void
    {
        $controls = ControlEvaluator::evaluate(
            [['metric' => 'merge-rate', 'variant' => 'a', 'expect' => '> 0', 'share' => 0.5]],
            ['merge-rate' => ['a' => [1]]],
            ['a' => 1],
            ['merge-rate' => 'Merge rate'],
        );

        self::assertSame('Merge rate', $controls[0]->templateLabel);
    }

    public function test_the_hold_names_the_first_kind_of_result_that_any_control_has(): void
    {
        $specs = [];
        $values = [];
        foreach (['no-data' => [], 'unknown' => null, 'failed' => ['a' => [0]], 'fine' => ['a' => [1]]] as $name => $cards) {
            $specs[] = ['metric' => $name, 'variant' => 'a', 'expect' => '> 0', 'share' => 1.0];
            $values[$name] = $cards;
        }
        $controls = ControlEvaluator::evaluate($specs, $values, ['a' => 1]);

        $hold = ControlEvaluator::hold($controls);

        self::assertNotNull($hold);
        self::assertSame(ControlResult::Failed, $hold->reason);
        self::assertSame(['failed'], array_map(static fn ($control): string => $control->metric, $hold->controls));
        self::assertSame(4, $hold->total);
        self::assertSame(ControlResult::UnknownMetric, ControlEvaluator::hold([$controls[1], $controls[0], $controls[3]])?->reason);
        self::assertSame(ControlResult::NoData, ControlEvaluator::hold([$controls[0], $controls[3]])?->reason);
        self::assertNull(ControlEvaluator::hold([$controls[3]]));
        self::assertNull(ControlEvaluator::hold([]));
    }
}
