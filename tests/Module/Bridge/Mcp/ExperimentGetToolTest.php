<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Mcp;

use App\Module\Bridge\Command\ShowExperimentHandler;
use App\Module\Bridge\Entity\ExperimentDefinition;
use App\Module\Bridge\Experiment\Stats;
use App\Module\Bridge\Mcp\ExperimentGetTool;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Tests\Module\Bridge\ExperimentScenario;
use App\Tests\Support\McpTokenScenario;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExperimentGetToolTest extends KernelTestCase
{
    use ExperimentScenario;
    use McpTokenScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_it_answers_the_comparison_and_the_cards_of_an_experiment(): void
    {
        $em = $this->em();
        $project = $this->boardProject($em, $this->user($em, 'experiment-get@example.com'));
        $definition = new ExperimentDefinition($project, 'model-test', [['name' => 'a', 'weight' => 3], ['name' => 'b', 'weight' => 1]]);
        $definition->baseline = 'a';
        $definition->metrics = ['cost', 'merge-rate', 'no-such-metric'];
        $em->persist($definition);
        for ($i = 1; $i <= 5; ++$i) {
            $card = $this->experimentCard($em, $project, $i, merged: true);
            $this->seedUsage($em, $this->experimentRun($em, $project, $card, 'a', state: WorkerRunState::Failed), costUsd: '2.000000');
            $card = $this->experimentCard($em, $project, $i + 5, merged: true);
            $this->seedUsage($em, $this->experimentRun($em, $project, $card, 'b'), costUsd: '1.000000');
        }
        $switched = $this->experimentCard($em, $project, 11);
        $this->experimentRun($em, $project, $switched, 'b', switchedFrom: 'a', at: '-1 hour');
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->tool()('model-test');

        self::assertSame('model-test', $result['experiment']);
        self::assertSame(['cost', 'merge-rate', 'no-such-metric'], $result['declaredMetrics']);
        self::assertSame(['no-such-metric'], $result['unknownMetrics']);
        self::assertSame(Stats::MIN_FINISHED_CARDS, $result['minFinishedCards']);
        self::assertSame([], $result['unknownGuards']);
        self::assertSame('a', $result['baseline']);
        self::assertSame(0.95, $result['confidenceLevel']);
        self::assertSame([
            'key' => 'cost',
            'label' => 'Cost per merged card',
            'valueType' => 'money',
            'direction' => 'lower',
            'problem' => null,
        ], $result['headline']['goal']);
        self::assertSame(['name' => 'a', 'point' => 2.0, 'cardsBehind' => 5], $result['headline']['baselineArm']);
        self::assertSame([[
            'variant' => 'b',
            'state' => 'clear',
            'point' => 1.0,
            'cardsBehind' => 5,
            'changeKind' => 'relative',
            'change' => ['point' => -0.5, 'low' => -0.5, 'high' => -0.5],
            'holdReason' => null,
            'heldBy' => null,
            'guards' => [['key' => 'merge-rate', 'point' => 1.0, 'change' => $result['headline']['comparisons'][0]['guards'][0]['change']]],
        ]], $result['headline']['comparisons']);
        self::assertEqualsWithDelta(0.0, $result['headline']['comparisons'][0]['guards'][0]['change']['point'] ?? null, 1e-12);
        self::assertSame([], $result['headline']['controls']);
        self::assertSame([['key' => 'merge-rate', 'label' => 'Merge rate', 'valueType' => 'ratio', 'baselinePoint' => 1.0]], $result['headline']['guards']);
        self::assertSame([
            ['name' => 'a', 'models' => ['model-a'], 'weight' => 3, 'cards' => 5, 'finishedCards' => 5, 'runs' => 5, 'costUsd' => 10.0, 'coveredCostUsd' => 10.0],
            ['name' => 'b', 'models' => ['model-b'], 'weight' => 1, 'cards' => 5, 'finishedCards' => 5, 'runs' => 5, 'costUsd' => 5.0, 'coveredCostUsd' => 5.0],
        ], $result['variants']);

        self::assertSame(['cost', 'merge-rate'], array_column($result['metrics'], 'key'));
        self::assertSame([
            'key' => 'cost',
            'label' => 'Cost per merged card',
            'valueType' => 'money',
            'clear' => true,
            'byVariant' => [
                'a' => ['point' => 2.0, 'low' => 2.0, 'high' => 2.0],
                'b' => ['point' => 1.0, 'low' => 1.0, 'high' => 1.0],
            ],
            'parts' => [],
        ], $result['metrics'][0]);
        self::assertSame('ratio', $result['metrics'][1]['valueType']);

        self::assertSame(10, $result['includedCards']);
        self::assertSame(1, $result['leftOutCards']);
        self::assertSame([1, 1, 11, false], [$result['page'], $result['totalPages'], $result['total'], $result['hasMore']]);
        self::assertSame([
            'cardId' => (string) $switched->id,
            'number' => 11,
            'title' => 'Card 11',
            'variant' => 'b',
            'column' => 'Done',
            'runs' => 1,
            'fixRounds' => 0,
            'costUsd' => null,
            'coveredCostUsd' => null,
            'leftOut' => ['switched'],
        ], $result['cards'][0]);
        self::assertSame(2.0, $this->cardOf($result['cards'], 1)['costUsd']);
        self::assertSame(2.0, $this->cardOf($result['cards'], 1)['coveredCostUsd']);
    }

    public function test_it_answers_the_covered_cost_beside_the_cost_of_every_run(): void
    {
        $em = $this->em();
        $project = $this->boardProject($em, $this->user($em, 'experiment-get-covered@example.com'));
        $definition = new ExperimentDefinition($project, 'model-test', [['name' => 'a', 'weight' => 1]]);
        $definition->metrics = ['cost', 'covered-cost'];
        $definition->covers = ['implement'];
        $em->persist($definition);
        $card = $this->experimentCard($em, $project, 1, merged: true);
        $this->seedUsage($em, $this->experimentRun($em, $project, $card, 'a', at: '-3 hours'), costUsd: '1.000000');
        $this->seedUsage($em, $this->experimentRun($em, $project, $card, 'a', at: '-2 hours', workKind: 'fix'), costUsd: '2.000000');
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->tool()('model-test');

        self::assertSame(['cost', 'covered-cost'], array_column($result['metrics'], 'key'));
        self::assertSame('Covered cost per merged card', $result['metrics'][1]['label']);
        self::assertSame('money', $result['metrics'][1]['valueType']);
        self::assertSame(3.0, $result['metrics'][0]['byVariant']['a']['point'] ?? null);
        self::assertSame(1.0, $result['metrics'][1]['byVariant']['a']['point'] ?? null);
        self::assertSame([2, 3.0, 1.0], [$result['variants'][0]['runs'], $result['variants'][0]['costUsd'], $result['variants'][0]['coveredCostUsd']]);
        self::assertSame([2, 3.0, 1.0], [$result['cards'][0]['runs'], $result['cards'][0]['costUsd'], $result['cards'][0]['coveredCostUsd']]);
    }

    public function test_a_goal_that_no_metric_has_names_the_problem(): void
    {
        $em = $this->em();
        $project = $this->boardProject($em, $this->user($em, 'experiment-get-typo@example.com'));
        $definition = new ExperimentDefinition($project, 'model-test', [['name' => 'a', 'weight' => 1], ['name' => 'b', 'weight' => 1]]);
        $definition->baseline = 'a';
        $definition->headline = ['goal' => ['metric' => 'hours-to-mrege', 'better' => 'lower'], 'guards' => []];
        $em->persist($definition);
        for ($i = 1; $i <= 5; ++$i) {
            $this->seedUsage($em, $this->experimentRun($em, $project, $this->experimentCard($em, $project, $i, merged: true), 'a'), costUsd: '2.000000');
            $this->seedUsage($em, $this->experimentRun($em, $project, $this->experimentCard($em, $project, $i + 5, merged: true), 'b'), costUsd: '1.000000');
        }
        $this->actAsMcpTokenBoundTo($project);

        $goal = $this->tool()('model-test')['headline']['goal'];

        self::assertSame('hours-to-mrege', $goal['key']);
        self::assertSame('hours-to-mrege', $goal['label']);
        self::assertNull($goal['valueType']);
        self::assertSame('unknown-metric', $goal['problem']);
        self::assertSame([], $this->tool()('model-test')['headline']['comparisons']);
    }

    public function test_every_other_variant_is_compared_against_the_baseline_at_a_widened_level(): void
    {
        $em = $this->em();
        $project = $this->boardProject($em, $this->user($em, 'experiment-get-three@example.com'));
        $definition = new ExperimentDefinition($project, 'model-test', [['name' => 'a', 'weight' => 1], ['name' => 'b', 'weight' => 1], ['name' => 'c', 'weight' => 1]]);
        $definition->baseline = 'b';
        $definition->metrics = ['cost'];
        $em->persist($definition);
        $number = 0;
        foreach (['a' => '4.000000', 'b' => '2.000000', 'c' => '1.000000'] as $variant => $cost) {
            for ($i = 0; $i < 5; ++$i) {
                $this->seedUsage($em, $this->experimentRun($em, $project, $this->experimentCard($em, $project, ++$number, merged: true), $variant), costUsd: $cost);
            }
        }
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->tool()('model-test');

        self::assertSame('b', $result['baseline']);
        self::assertSame(0.975, $result['confidenceLevel']);
        self::assertSame('b', $result['headline']['baselineArm']['name'] ?? null);
        self::assertSame(['a', 'c'], array_column($result['headline']['comparisons'], 'variant'));
        self::assertSame(['clear', 'clear'], array_column($result['headline']['comparisons'], 'state'));
        self::assertSame([1.0, -0.5], array_map(static fn (array $comparison): ?float => $comparison['change']['point'] ?? null, $result['headline']['comparisons']));
        self::assertTrue($result['metrics'][0]['clear']);
    }

    public function test_an_experiment_with_no_baseline_has_no_comparison(): void
    {
        $em = $this->em();
        $project = $this->boardProject($em, $this->user($em, 'experiment-get-nobaseline@example.com'));
        $definition = new ExperimentDefinition($project, 'model-test', [['name' => 'a', 'weight' => 1], ['name' => 'b', 'weight' => 1]]);
        $definition->metrics = ['cost'];
        $em->persist($definition);
        for ($i = 1; $i <= 5; ++$i) {
            $this->seedUsage($em, $this->experimentRun($em, $project, $this->experimentCard($em, $project, $i, merged: true), 'a'), costUsd: '2.000000');
            $this->seedUsage($em, $this->experimentRun($em, $project, $this->experimentCard($em, $project, $i + 5, merged: true), 'b'), costUsd: '1.000000');
        }
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->tool()('model-test');

        self::assertNull($result['baseline']);
        self::assertNull($result['headline']['baselineArm']);
        self::assertSame([], $result['headline']['comparisons']);
        self::assertSame(0.95, $result['confidenceLevel']);
        self::assertNull($result['metrics'][0]['clear']);
        self::assertSame(['a', 'b'], array_keys($result['metrics'][0]['byVariant']));
    }

    public function test_the_description_says_every_variant_is_compared_against_the_baseline(): void
    {
        $attributes = new \ReflectionClass(ExperimentGetTool::class)->getAttributes(McpTool::class);
        $description = $attributes[0]->newInstance()->description ?? '';

        self::assertStringContainsString('compared against the baseline', $description);
        self::assertStringContainsString('comparisons is empty and baseline is null', $description);
        self::assertStringContainsString('not-enough-cards, clear, not-clear or held', $description);
        self::assertStringContainsString('confidenceLevel', $description);
        self::assertStringNotContainsString('first two variants', $description);
        self::assertStringNotContainsString('winner', $description);
    }

    public function test_a_failed_control_holds_its_comparison_and_the_tool_lists_it(): void
    {
        $em = $this->em();
        $project = $this->boardProject($em, $this->user($em, 'experiment-get-controls@example.com'));
        $definition = new ExperimentDefinition($project, 'model-test', [['name' => 'a', 'weight' => 1], ['name' => 'b', 'weight' => 1]]);
        $definition->baseline = 'b';
        $definition->headline = ['goal' => ['metric' => 'cost', 'better' => 'lower'], 'guards' => [], 'controls' => [
            ['metric' => 'harness-events:diagnostics', 'variant' => 'a', 'expect' => '> 0', 'share' => 0.5],
            ['metric' => 'bucket-calls:lps', 'variant' => 'b', 'expect' => '> 0', 'share' => 0.5],
        ]];
        $em->persist($definition);
        for ($i = 1; $i <= 5; ++$i) {
            $run = $this->experimentRun($em, $project, $this->experimentCard($em, $project, $i, merged: true), 'a');
            $run->harnessEvents = 1 === $i ? ['diagnostics' => 1] : [];
            $this->seedUsage($em, $run, costUsd: '2.000000');
            $this->seedUsage($em, $this->experimentRun($em, $project, $this->experimentCard($em, $project, $i + 5, merged: true), 'b'), costUsd: '1.000000');
        }
        $em->flush();
        $this->actAsMcpTokenBoundTo($project);

        $headline = $this->tool()('model-test')['headline'];

        [$comparison] = $headline['comparisons'];
        self::assertSame('a', $comparison['variant']);
        self::assertSame('held', $comparison['state']);
        self::assertSame('failed', $comparison['holdReason']);
        self::assertSame(['harness-events:diagnostics'], $comparison['heldBy']);
        self::assertNull($comparison['change']);
        self::assertSame([
            ['metric' => 'harness-events:diagnostics', 'variant' => 'a', 'expect' => '> 0', 'share' => 0.5, 'passingShare' => 0.2, 'cardsWithData' => 5, 'cardsFinished' => 5, 'result' => 'failed'],
            ['metric' => 'bucket-calls:lps', 'variant' => 'b', 'expect' => '> 0', 'share' => 0.5, 'passingShare' => null, 'cardsWithData' => 0, 'cardsFinished' => 5, 'result' => 'unknown-metric'],
        ], $headline['controls']);
    }

    public function test_the_metric_parts_take_the_value_type_of_their_metric(): void
    {
        $em = $this->em();
        $project = $this->boardProject($em, $this->user($em, 'experiment-get-parts@example.com'));
        $this->bindLifecycle($em, $project);
        $definition = new ExperimentDefinition($project, 'model-test', [['name' => 'a', 'weight' => 1]]);
        $definition->metrics = ['fix-rounds'];
        $em->persist($definition);
        $card = $this->experimentCard($em, $project, 1, merged: true);
        $this->fixRequest($em, $project, $card, 'conflict');
        $this->experimentRun($em, $project, $card, 'a');
        $this->actAsMcpTokenBoundTo($project);

        $metric = $this->tool()('model-test')['metrics'][0];

        self::assertSame('fix-rounds', $metric['key']);
        self::assertSame('Fix rounds', $metric['label']);
        self::assertSame('count', $metric['valueType']);
        self::assertNull($metric['clear']);
        self::assertSame([[
            'key' => 'conflict',
            'label' => 'conflict',
            'valueType' => 'count',
            'clear' => null,
            'byVariant' => ['a' => ['point' => 1.0, 'low' => 1.0, 'high' => 1.0]],
            'parts' => [],
        ]], $metric['parts']);
    }

    public function test_it_pages_and_filters_the_cards(): void
    {
        $em = $this->em();
        $project = $this->boardProject($em, $this->user($em, 'experiment-get-pages@example.com'));
        $count = ShowExperimentHandler::PER_PAGE + 1;
        for ($i = 1; $i <= $count; ++$i) {
            $this->experimentRun($em, $project, $this->experimentCard($em, $project, $i), 0 === $i % 2 ? 'b' : 'a');
        }
        $switched = $this->experimentCard($em, $project, $count + 1);
        $this->experimentRun($em, $project, $switched, 'b', switchedFrom: 'a');
        $this->actAsMcpTokenBoundTo($project);

        $first = $this->tool()('model-test');
        $second = $this->tool()('model-test', page: 2);

        self::assertCount(ShowExperimentHandler::PER_PAGE, $first['cards']);
        self::assertSame([1, 2, $count + 1, true], [$first['page'], $first['totalPages'], $first['total'], $first['hasMore']]);
        self::assertCount(2, $second['cards']);
        self::assertSame([2, false], [$second['page'], $second['hasMore']]);

        $variantB = $this->tool()('model-test', variant: 'b');
        self::assertSame(intdiv($count, 2), $variantB['total']);
        self::assertSame(['b'], array_values(array_unique(array_column($variantB['cards'], 'variant'))));

        $leftOut = $this->tool()('model-test', leftOutOnly: true);
        self::assertSame([(string) $switched->id], array_column($leftOut['cards'], 'cardId'));
    }

    public function test_an_unknown_experiment_is_refused(): void
    {
        $em = $this->em();
        $project = $this->boardProject($em, $this->user($em, 'experiment-get-unknown@example.com'));
        $this->actAsMcpTokenBoundTo($project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('No experiment named "no-such-test" exists in this project.');

        $this->tool()('no-such-test');
    }

    public function test_an_experiment_of_another_project_is_unknown(): void
    {
        $em = $this->em();
        $project = $this->boardProject($em, $this->user($em, 'experiment-get-mine@example.com'));
        $other = $this->boardProject($em, $this->user($em, 'experiment-get-other@example.com'));
        $this->experimentRun($em, $other, $this->experimentCard($em, $other, 1), 'a');
        $this->actAsMcpTokenBoundTo($project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('No experiment named "model-test" exists in this project.');

        $this->tool()('model-test');
    }

    public function test_it_refuses_an_unbound_token(): void
    {
        $em = $this->em();
        $project = $this->boardProject($em, $this->user($em, 'experiment-get-unbound@example.com'));
        $this->actAsUnboundMcpToken($project->owner);

        $this->expectException(ToolCallException::class);

        $this->tool()('model-test');
    }

    /**
     * @param list<array{cardId: string, number: ?int, costUsd: ?float, coveredCostUsd: ?float}> $cards
     *
     * @return array{cardId: string, number: ?int, costUsd: ?float, coveredCostUsd: ?float}
     */
    private function cardOf(array $cards, int $number): array
    {
        foreach ($cards as $card) {
            if ($number === $card['number']) {
                return $card;
            }
        }

        self::fail(\sprintf('No card %d.', $number));
    }

    private function tool(): ExperimentGetTool
    {
        $tool = self::getContainer()->get(ExperimentGetTool::class);
        self::assertInstanceOf(ExperimentGetTool::class, $tool);

        return $tool;
    }
}
