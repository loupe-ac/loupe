<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller\Api;

use App\Module\Bridge\Controller\Api\ResolveExperimentPinRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResolveExperimentPinRequestTest extends TestCase
{
    public function test_valid_weights_map_each_variant_to_its_weight(): void
    {
        $request = new ResolveExperimentPinRequest('sonnet', ['opus', 'sonnet'], [1, 1_000_000]);

        self::assertSame(
            [['name' => 'opus', 'weight' => 1], ['name' => 'sonnet', 'weight' => 1_000_000]],
            $request->weights(),
        );
    }

    /** PHP turns a numeric string key into an int, so a map would lose these names. */
    public function test_numeric_variant_names_keep_their_names(): void
    {
        $request = new ResolveExperimentPinRequest('1', ['0', '1'], [1, 2]);

        self::assertSame([['name' => '0', 'weight' => 1], ['name' => '1', 'weight' => 2]], $request->weights());
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidWeights(): iterable
    {
        yield 'absent' => [null];
        yield 'not a list' => [['opus' => 1, 'sonnet' => 2]];
        yield 'a string' => ['1,2'];
        yield 'too few' => [[1]];
        yield 'too many' => [[1, 2, 3]];
        yield 'empty' => [[]];
        yield 'zero' => [[0, 2]];
        yield 'negative' => [[-1, 2]];
        yield 'too large' => [[1, 1_000_001]];
        yield 'a float' => [[1.5, 2]];
        yield 'a numeric string' => [['1', 2]];
        yield 'a boolean' => [[true, 2]];
        yield 'null in the list' => [[null, 2]];
    }

    #[DataProvider('invalidWeights')]
    public function test_invalid_weights_give_null(mixed $weights): void
    {
        $request = new ResolveExperimentPinRequest('sonnet', ['opus', 'sonnet'], $weights);

        self::assertNotNull($request->choice());
        self::assertNull($request->weights());
    }

    public function test_valid_weights_with_an_invalid_choice_give_null(): void
    {
        $request = new ResolveExperimentPinRequest('haiku', ['opus', 'sonnet'], [1, 2]);

        self::assertNull($request->choice());
        self::assertNull($request->weights());
    }

    public function test_valid_metrics_keep_their_order(): void
    {
        $request = new ResolveExperimentPinRequest('sonnet', ['opus', 'sonnet'], [1, 1], ['merge-rate', 'cost', 'unknown:key']);

        self::assertSame(['merge-rate', 'cost', 'unknown:key'], $request->metrics());
    }

    public function test_sixteen_metrics_are_kept(): void
    {
        $metrics = array_map(static fn (int $i): string => 'm'.$i, range(1, ResolveExperimentPinRequest::MAX_METRICS));

        self::assertSame($metrics, new ResolveExperimentPinRequest(metrics: $metrics)->metrics());
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidMetrics(): iterable
    {
        yield 'absent' => [null];
        yield 'empty' => [[]];
        yield 'a string' => ['cost'];
        yield 'not a list' => [['a' => 'cost']];
        yield 'too many' => [array_map(static fn (int $i): string => 'm'.$i, range(1, ResolveExperimentPinRequest::MAX_METRICS + 1))];
        yield 'a number' => [['cost', 1]];
        yield 'null in the list' => [['cost', null]];
        yield 'a capital' => [['Cost']];
        yield 'a digit first' => [['1cost']];
        yield 'too long' => [['c'.str_repeat('o', 128)]];
        yield 'a repeat' => [['cost', 'cost']];
        yield 'a trailing newline' => [["cost\n"]];
    }

    #[DataProvider('invalidMetrics')]
    public function test_invalid_metrics_give_null(mixed $metrics): void
    {
        $request = new ResolveExperimentPinRequest('sonnet', ['opus', 'sonnet'], [1, 1], $metrics);

        self::assertNotNull($request->weights());
        self::assertNull($request->metrics());
    }

    public function test_a_full_headline_is_kept(): void
    {
        $request = new ResolveExperimentPinRequest(headline: ['goal' => ['metric' => 'hours-to-merge', 'better' => 'lower'], 'guards' => ['merge-rate', 'fix-rounds']]);

        self::assertSame(['goal' => ['metric' => 'hours-to-merge', 'better' => 'lower'], 'guards' => ['merge-rate', 'fix-rounds']], $request->headline());
    }

    public function test_a_goal_alone_and_guards_alone_are_kept(): void
    {
        self::assertSame(['goal' => ['metric' => 'cost', 'better' => 'higher']], new ResolveExperimentPinRequest(headline: ['goal' => ['metric' => 'cost', 'better' => 'higher']])->headline());
        self::assertSame(['goal' => ['metric' => 'cost']], new ResolveExperimentPinRequest(headline: ['goal' => ['metric' => 'cost']])->headline());
        self::assertSame(['goal' => ['metric' => 'cost']], new ResolveExperimentPinRequest(headline: ['goal' => ['metric' => 'cost', 'better' => null]])->headline());
        self::assertSame(['guards' => ['cost']], new ResolveExperimentPinRequest(headline: ['guards' => ['cost']])->headline());
    }

    public function test_empty_guards_mean_no_guards_and_stay(): void
    {
        self::assertSame(['guards' => []], new ResolveExperimentPinRequest(headline: ['guards' => []])->headline());
    }

    public function test_unknown_keys_are_dropped(): void
    {
        $request = new ResolveExperimentPinRequest(headline: ['extra' => 1, 'goal' => ['metric' => 'cost', 'better' => 'lower', 'weight' => 2], 'guards' => ['merge-rate']]);

        self::assertSame(['goal' => ['metric' => 'cost', 'better' => 'lower'], 'guards' => ['merge-rate']], $request->headline());
    }

    public function test_sixteen_guards_are_kept(): void
    {
        $guards = array_map(static fn (int $i): string => 'm'.$i, range(1, ResolveExperimentPinRequest::MAX_METRICS));

        self::assertSame(['guards' => $guards], new ResolveExperimentPinRequest(headline: ['guards' => $guards])->headline());
    }

    public function test_controls_are_kept_with_the_share_as_a_float(): void
    {
        $request = new ResolveExperimentPinRequest('lsp', ['lsp', 'no-lsp'], [1, 1], null, ['controls' => [
            ['metric' => 'harness-events:diagnostics', 'variant' => 'lsp', 'expect' => '> 0', 'share' => 1, 'extra' => true],
            ['metric' => 'bucket-calls:lsp', 'variant' => 'no-lsp', 'expect' => '== 0', 'share' => 0.5],
        ]]);

        self::assertSame(['controls' => [
            ['metric' => 'harness-events:diagnostics', 'variant' => 'lsp', 'expect' => '> 0', 'share' => 1.0],
            ['metric' => 'bucket-calls:lsp', 'variant' => 'no-lsp', 'expect' => '== 0', 'share' => 0.5],
        ]], $request->headline());
    }

    public function test_controls_sit_beside_a_goal_and_guards(): void
    {
        $request = new ResolveExperimentPinRequest('a', ['a', 'b'], [1, 1], null, ['goal' => ['metric' => 'cost'], 'guards' => [], 'controls' => [['metric' => 'cost', 'variant' => 'a', 'expect' => '>=1.5', 'share' => 0.25]]]);

        self::assertSame(['goal' => ['metric' => 'cost'], 'guards' => [], 'controls' => [['metric' => 'cost', 'variant' => 'a', 'expect' => '>=1.5', 'share' => 0.25]]], $request->headline());
    }

    public function test_empty_controls_alone_give_no_headline(): void
    {
        self::assertNull(new ResolveExperimentPinRequest('a', ['a'], [1], null, ['controls' => []])->headline());
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidControls(): iterable
    {
        $valid = ['metric' => 'cost', 'variant' => 'a', 'expect' => '> 0', 'share' => 0.5];
        yield 'not a list' => [['x' => $valid]];
        yield 'a string' => ['cost'];
        yield 'too many' => [array_map(static fn (int $i): array => ['metric' => 'm'.$i] + $valid, range(1, ResolveExperimentPinRequest::MAX_CONTROLS + 1))];
        yield 'an entry that is a list' => [[['cost', 'a', '> 0', 0.5]]];
        yield 'metric bad shape' => [[['metric' => 'Cost'] + $valid]];
        yield 'metric missing' => [[['variant' => 'a', 'expect' => '> 0', 'share' => 0.5]]];
        yield 'variant not declared' => [[['variant' => 'z'] + $valid]];
        yield 'variant a number' => [[['variant' => 1] + $valid]];
        yield 'expect without a number' => [[['expect' => '>'] + $valid]];
        yield 'expect not a comparison' => [[['expect' => 'about 3'] + $valid]];
        yield 'expect trailing newline' => [[['expect' => "> 0\n"] + $valid]];
        yield 'share missing' => [[['metric' => 'cost', 'variant' => 'a', 'expect' => '> 0']]];
        yield 'share zero' => [[['share' => 0] + $valid]];
        yield 'share above one' => [[['share' => 1.5] + $valid]];
        yield 'share a string' => [[['share' => '0.5'] + $valid]];
        yield 'repeat' => [[$valid, ['expect' => '< 5'] + $valid]];
    }

    #[DataProvider('invalidControls')]
    public function test_invalid_controls_give_no_headline(mixed $controls): void
    {
        $request = new ResolveExperimentPinRequest('a', ['a', 'b'], [1, 1], null, ['goal' => ['metric' => 'cost'], 'controls' => $controls]);

        self::assertNull($request->headline());
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidHeadlines(): iterable
    {
        yield 'absent' => [null];
        yield 'empty' => [[]];
        yield 'a string' => ['cost'];
        yield 'a list' => [['cost']];
        yield 'no known key' => [['extra' => 1]];
        yield 'goal not an object' => [['goal' => 'cost']];
        yield 'goal without metric' => [['goal' => ['better' => 'lower']]];
        yield 'empty goal' => [['goal' => []]];
        yield 'bad better' => [['goal' => ['metric' => 'cost', 'better' => 'up']]];
        yield 'better capital' => [['goal' => ['metric' => 'cost', 'better' => 'Lower']]];
        yield 'better not a string' => [['goal' => ['metric' => 'cost', 'better' => true]]];
        yield 'goal metric a capital' => [['goal' => ['metric' => 'Cost', 'better' => 'lower']]];
        yield 'goal metric a number' => [['goal' => ['metric' => 1, 'better' => 'lower']]];
        yield 'goal metric trailing newline' => [['goal' => ['metric' => "cost\n", 'better' => 'lower']]];
        yield 'guards a string' => [['guards' => 'cost']];
        yield 'guards not a list' => [['guards' => ['a' => 'cost']]];
        yield 'guards too many' => [['guards' => array_map(static fn (int $i): string => 'm'.$i, range(1, ResolveExperimentPinRequest::MAX_METRICS + 1))]];
        yield 'guard a number' => [['guards' => ['cost', 1]]];
        yield 'guard bad shape' => [['guards' => ['Cost']]];
        yield 'guard repeat' => [['guards' => ['cost', 'cost']]];
        yield 'goal metric in guards' => [['goal' => ['metric' => 'cost', 'better' => 'lower'], 'guards' => ['merge-rate', 'cost']]];
        yield 'valid goal, bad guards' => [['goal' => ['metric' => 'cost', 'better' => 'lower'], 'guards' => ['cost', 'cost']]];
    }

    #[DataProvider('invalidHeadlines')]
    public function test_an_invalid_headline_gives_null(mixed $headline): void
    {
        $request = new ResolveExperimentPinRequest('sonnet', ['opus', 'sonnet'], [1, 1], null, $headline);

        self::assertNotNull($request->weights());
        self::assertNull($request->headline());
    }

    public function test_valid_covers_come_back_sorted(): void
    {
        $request = new ResolveExperimentPinRequest(covers: ['implement', 'review:agent', 'fix']);

        self::assertSame(['fix', 'implement', 'review:agent'], $request->covers());
    }

    public function test_thirty_two_covers_are_kept(): void
    {
        $covers = array_map(static fn (int $i): string => 'k'.$i, range(1, ResolveExperimentPinRequest::MAX_COVERS));

        self::assertCount(ResolveExperimentPinRequest::MAX_COVERS, new ResolveExperimentPinRequest(covers: $covers)->covers() ?? []);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidCovers(): iterable
    {
        yield 'absent' => [null];
        yield 'a string' => ['fix'];
        yield 'empty' => [[]];
        yield 'not a list' => [['a' => 'fix']];
        yield 'a duplicate' => [['fix', 'fix']];
        yield 'too many' => [array_map(static fn (int $i): string => 'k'.$i, range(1, ResolveExperimentPinRequest::MAX_COVERS + 1))];
        yield 'an int' => [[1]];
        yield 'null in the list' => [['fix', null]];
        yield 'upper case' => [['Fix']];
        yield 'free text' => [['fix it']];
        yield 'too long' => [[str_repeat('a', 41)]];
    }

    #[DataProvider('invalidCovers')]
    public function test_invalid_covers_give_null(mixed $covers): void
    {
        self::assertNull(new ResolveExperimentPinRequest(covers: $covers)->covers());
    }

    public function test_a_baseline_that_is_a_variant_comes_back(): void
    {
        $request = new ResolveExperimentPinRequest('sonnet', ['opus', 'sonnet'], baseline: 'opus');

        self::assertSame('opus', $request->baseline());
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidBaselines(): iterable
    {
        yield 'absent' => [null];
        yield 'not a variant' => ['haiku'];
        yield 'an int' => [1];
        yield 'a list' => [['opus']];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidBaselines')]
    public function test_an_invalid_baseline_gives_null(mixed $baseline): void
    {
        self::assertNull(new ResolveExperimentPinRequest('sonnet', ['opus', 'sonnet'], baseline: $baseline)->baseline());
    }

    public function test_a_baseline_with_invalid_variants_gives_null(): void
    {
        self::assertNull(new ResolveExperimentPinRequest('sonnet', ['opus', 'opus'], baseline: 'opus')->baseline());
    }
}
