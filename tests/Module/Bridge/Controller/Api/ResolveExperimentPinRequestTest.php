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
        yield 'too long' => [['c'.str_repeat('o', 64)]];
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

    /** @return iterable<string, array{mixed}> */
    public static function invalidHeadlines(): iterable
    {
        yield 'absent' => [null];
        yield 'empty' => [[]];
        yield 'a string' => ['cost'];
        yield 'a list' => [['cost']];
        yield 'no known key' => [['extra' => 1]];
        yield 'goal not an object' => [['goal' => 'cost']];
        yield 'goal without better' => [['goal' => ['metric' => 'cost']]];
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
}
