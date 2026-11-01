<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Mcp;

use App\Exception\DomainErrors;
use App\Mercure\LiveUpdatePublisher;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Insights\Command\RemoveSavedGraphCommand;
use App\Module\Insights\Command\RemoveSavedGraphHandler;
use App\Module\Insights\Command\RestoreSavedGraphCommand;
use App\Module\Insights\Command\RestoreSavedGraphHandler;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Entity\SavedGraphWidth;
use App\Module\Insights\Mcp\GraphListTool;
use App\Module\Insights\Mcp\GraphMoveTool;
use App\Module\Insights\Mcp\GraphRemoveTool;
use App\Module\Insights\Mcp\GraphRestoreTool;
use App\Module\Insights\Mcp\GraphSaveTool;
use App\Module\Insights\Mcp\GraphUpdateTool;
use App\Module\Insights\Mcp\InsightsToolErrorMessages;
use App\Module\Insights\Mcp\SavedGraphFinder;
use App\Module\Insights\Service\SavedGraphCap;
use App\Module\Insights\Service\SavedGraphLocker;
use App\Module\Insights\Service\SavedGraphSettingsResolver;
use App\Module\Insights\View\MetricChartLock;
use App\Module\Review\Service\WorkerSession;
use App\Tests\Module\Insights\InsightsScenario;
use App\Tests\Support\McpTokenScenario;
use Mcp\Exception\ToolCallException;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GraphToolsTest extends KernelTestCase
{
    use InsightsScenario;
    use McpTokenScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_list_answers_an_empty_dashboard(): void
    {
        $this->actAsMcpTokenBoundTo($this->scenarioProject('graphs-empty'));

        self::assertSame(['graphs' => [], 'limit' => SavedGraphCap::DEFAULT, 'removed' => [], 'removedOmitted' => 0], $this->tool(GraphListTool::class)());
    }

    public function test_list_gives_the_graphs_in_dashboard_order_with_their_settings_and_health(): void
    {
        $project = $this->scenarioProject('graphs-list');
        $second = $this->seedSavedGraph($project, 'Second', 5);
        $first = $this->seedSavedGraph($project, 'First', 2);
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->tool(GraphListTool::class)();

        self::assertSame(['First', 'Second'], array_column($result['graphs'], 'name'));
        self::assertSame([0, 1], array_column($result['graphs'], 'position'));
        self::assertSame((string) $first->id, $result['graphs'][0]['id']);
        self::assertSame((string) $second->id, $result['graphs'][1]['id']);
        self::assertSame('half', $result['graphs'][0]['width']);
        self::assertTrue($result['graphs'][0]['showLegend']);
        self::assertSame('bucket-time:tests', $result['graphs'][0]['settings']['metric']);
        self::assertSame(['reason' => 'bucket', 'name' => 'tests'], $result['graphs'][0]['broken']);
    }

    public function test_list_names_the_newest_twenty_restorable_graphs_and_counts_the_rest(): void
    {
        $project = $this->scenarioProject('graphs-removed');
        $now = new \DateTimeImmutable();
        for ($i = 0; $i < 22; ++$i) {
            $this->seedSavedGraph($project, 'Gone '.$i, $i, $now->modify(\sprintf('-%d minutes', $i + 1)));
        }
        $this->seedSavedGraph($project, 'Expired', 30, $now->modify('-3 days'));
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->tool(GraphListTool::class)();

        self::assertSame([], $result['graphs']);
        self::assertCount(20, $result['removed']);
        self::assertSame('Gone 0', $result['removed'][0]['name']);
        self::assertSame(2, $result['removedOmitted']);
        self::assertGreaterThan($now->format(\DATE_ATOM), $result['removed'][0]['undoUntil']);
    }

    public function test_save_adds_a_tile_with_the_defaults_of_the_metrics_page(): void
    {
        $project = $this->scenarioProject('graphs-save');
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->tool(GraphSaveTool::class)('cost', 'card', 'sum');

        self::assertSame($result['graph'], $result['graphs'][0]);
        self::assertSame(['metric' => 'cost', 'unit' => 'card', 'statistic' => 'sum', 'group' => 'none', 'range' => 'ninety-days', 'bucket' => 'week', 'compare' => 'off', 'chart' => 'line'], $result['graph']['settings']);
        self::assertSame('half', $result['graph']['width']);
        self::assertTrue($result['graph']['showLegend']);
        self::assertNotSame('', $result['graph']['name']);
    }

    public function test_save_takes_every_setting_and_the_name(): void
    {
        $this->actAsMcpTokenBoundTo($this->scenarioProject('graphs-save-all'));

        $result = $this->tool(GraphSaveTool::class)('cost', 'card', 'mean', 'Cost per stage', 'stage', 'thirty-days', 'day', 'previous', 'bars');

        self::assertSame('Cost per stage', $result['graph']['name']);
        self::assertSame(['metric' => 'cost', 'unit' => 'card', 'statistic' => 'mean', 'group' => 'stage', 'range' => 'thirty-days', 'bucket' => 'day', 'compare' => 'previous', 'chart' => 'bars'], $result['graph']['settings']);
    }

    /** @return iterable<string, array{array<int, mixed>, string}> */
    public static function refusedSaves(): iterable
    {
        yield 'unknown statistic' => [['cost', 'card', 'average'], 'statistic: Unknown statistic "average". Use one of: median, mean, sum, p90, count, max.'];
        yield 'unknown metric' => [['nope', 'card', 'sum'], 'metric: Unknown metric "nope"'];
        yield 'bucket the range does not take' => [['cost', 'card', 'sum', null, null, 'seven-days', 'week'], 'bucket: The range seven-days does not take the bucket "week". Use one of: day.'];
        yield 'compare with all time' => [['cost', 'card', 'sum', null, null, 'all', null, 'previous'], 'compare: The range all has no previous period to compare with. Use off, or another range.'];
        yield 'unknown chart' => [['cost', 'card', 'sum', null, null, null, null, null, 'pie'], 'chart: Unknown chart "pie". Use one of:'];
        yield 'chart that needs several lines' => [['cost', 'card', 'sum', null, null, null, null, null, 'stacked-bars'], 'chart: This chart type needs several lines to add up. Group the graph first, or pick another chart type.'];
        yield 'chart whose statistic does not add' => [['cost', 'card', 'median', null, 'stage', null, null, null, 'donut'], 'chart: This chart type needs a sum, a count or a mean of a metric that adds up.'];
        yield 'chart whose metric is a rate' => [['stop-rate', 'run', 'mean', null, 'stage', null, null, null, 'donut'], 'chart: The rates of several lines never add up to a whole. Pick another chart type.'];
        yield 'name past the column' => [['cost', 'card', 'sum', str_repeat('a', 121)], 'name: A name is at most 120 characters.'];
    }

    /** @param array<int, mixed> $arguments */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedSaves')]
    public function test_save_refuses_what_the_metric_cannot_take_and_saves_nothing(array $arguments, string $message): void
    {
        $project = $this->scenarioProject('graphs-refused-'.md5($message));
        $this->actAsMcpTokenBoundTo($project);

        try {
            $this->tool(GraphSaveTool::class)(...$arguments);
            self::fail('Expected a refusal.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
        self::assertSame([], $this->tool(GraphListTool::class)()['graphs']);
    }

    public function test_every_chart_lock_has_a_sentence_for_an_agent(): void
    {
        foreach (MetricChartLock::cases() as $lock) {
            $errors = new DomainErrors(['chart' => SavedGraphSettingsResolver::CHART_LOCKED_PREFIX.str_replace('-', '_', $lock->value)]);

            self::assertStringNotContainsString(InsightsToolErrorMessages::UNMAPPED, new InsightsToolErrorMessages()->forAgent($errors)->getMessage(), $lock->value);
        }
    }

    public function test_save_refuses_a_full_dashboard_in_words(): void
    {
        $project = $this->scenarioProject('graphs-cap');
        for ($i = 0; $i < SavedGraphCap::DEFAULT; ++$i) {
            $this->seedSavedGraph($project, 'Graph '.$i, $i);
        }
        $this->actAsMcpTokenBoundTo($project);

        try {
            $this->tool(GraphSaveTool::class)('cost', 'card', 'sum');
            self::fail('Expected a refusal.');
        } catch (ToolCallException $e) {
            self::assertSame('The Dashboard holds the most graphs it can, 12. Remove a graph with graph_remove first.', $e->getMessage());
        }
    }

    public function test_update_changes_only_what_is_passed_and_answers_the_earlier_values(): void
    {
        $project = $this->scenarioProject('graphs-update');
        $graph = $this->seedSavedGraph($project, 'Tests');
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->tool(GraphUpdateTool::class)((string) $graph->id, name: 'Renamed', width: 'full', showLegend: false);

        self::assertSame('Renamed', $result['graph']['name']);
        self::assertSame('full', $result['graph']['width']);
        self::assertFalse($result['graph']['showLegend']);
        self::assertSame('bucket-time:tests', $result['graph']['settings']['metric']);
        self::assertSame(['name' => 'Tests', 'settings' => $graph->settings(), 'width' => 'half', 'showLegend' => true], $result['previous']);
    }

    public function test_update_overlays_the_settings_on_the_kept_ones(): void
    {
        $project = $this->scenarioProject('graphs-update-settings');
        $saved = $this->tool(GraphSaveTool::class);
        $this->actAsMcpTokenBoundTo($project);
        $graph = $saved('cost', 'card', 'median', 'Cost')['graph'];

        $result = $this->tool(GraphUpdateTool::class)($graph['id'], statistic: 'sum', chart: 'bars');

        self::assertSame('sum', $result['graph']['settings']['statistic']);
        self::assertSame('bars', $result['graph']['settings']['chart']);
        self::assertSame('cost', $result['graph']['settings']['metric']);
        self::assertSame('Cost', $result['graph']['name']);
        self::assertSame('median', $result['previous']['settings']['statistic']);
    }

    public function test_update_refuses_a_setting_that_clashes_with_a_kept_one(): void
    {
        $project = $this->scenarioProject('graphs-update-clash');
        $this->actAsMcpTokenBoundTo($project);
        $graph = $this->tool(GraphSaveTool::class)('cost', 'card', 'sum', null, null, 'thirty-days', 'week', 'previous')['graph'];

        try {
            $this->tool(GraphUpdateTool::class)($graph['id'], range: 'all');
            self::fail('Expected a refusal.');
        } catch (ToolCallException $e) {
            self::assertSame('compare: The range all has no previous period to compare with. Use off, or another range.', $e->getMessage());
        }
        self::assertSame('thirty-days', $this->tool(GraphListTool::class)()['graphs'][0]['settings']['range']);
    }

    public function test_a_name_only_update_leaves_the_settings_of_a_broken_graph_alone(): void
    {
        $project = $this->scenarioProject('graphs-update-broken');
        $graph = $this->seedSavedGraph($project, 'Tests');
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->tool(GraphUpdateTool::class)((string) $graph->id, name: 'Still tests');

        self::assertSame('Still tests', $result['graph']['name']);
        self::assertSame('bucket-time:tests', $result['graph']['settings']['metric']);
        self::assertSame(['reason' => 'bucket', 'name' => 'tests'], $result['graph']['broken']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refusedUpdates(): iterable
    {
        yield 'nothing to change' => [[], 'Pass at least one argument to change'];
        yield 'blank name' => [['name' => '  '], 'name: A name must not be blank.'];
        yield 'long name' => [['name' => str_repeat('a', 121)], 'name: A name is at most 120 characters.'];
        yield 'unknown width' => [['width' => 'wide'], 'width: Use half or full.'];
    }

    /** @param array<string, mixed> $arguments */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedUpdates')]
    public function test_update_refuses_bad_arguments(array $arguments, string $message): void
    {
        $project = $this->scenarioProject('graphs-update-bad-'.md5($message));
        $graph = $this->seedSavedGraph($project, 'Tests');
        $this->actAsMcpTokenBoundTo($project);

        try {
            $this->tool(GraphUpdateTool::class)((string) $graph->id, ...$arguments);
            self::fail('Expected a refusal.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_update_move_and_remove_refuse_a_graph_of_another_project_or_a_bad_id(): void
    {
        $foreign = $this->seedSavedGraph($this->scenarioProject('graphs-foreign'), 'Theirs');
        $this->actAsMcpTokenBoundTo($this->scenarioProject('graphs-mine'));

        foreach ([
            fn () => $this->tool(GraphUpdateTool::class)((string) $foreign->id, name: 'Mine now'),
            fn () => $this->tool(GraphMoveTool::class)((string) $foreign->id, 0),
            fn () => $this->tool(GraphRemoveTool::class)((string) $foreign->id),
            fn () => $this->tool(GraphRestoreTool::class)((string) $foreign->id),
            fn () => $this->tool(GraphUpdateTool::class)('not-an-id', name: 'x'),
        ] as $call) {
            try {
                $call();
                self::fail('Expected a refusal.');
            } catch (ToolCallException $e) {
                self::assertSame('graphId: No saved graph of this project has this id. Read the ids with graph_list.', $e->getMessage());
            }
        }
    }

    public function test_update_refuses_a_removed_graph_and_names_the_restore_tool(): void
    {
        $project = $this->scenarioProject('graphs-update-removed');
        $graph = $this->seedSavedGraph($project, 'Gone', 0, new \DateTimeImmutable('-1 hour'));
        $this->actAsMcpTokenBoundTo($project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Bring it back with graph_restore first.');

        $this->tool(GraphUpdateTool::class)((string) $graph->id, name: 'x');
    }

    public function test_each_write_answers_in_words_when_another_session_removes_the_graph_during_the_call(): void
    {
        $project = $this->scenarioProject('graphs-race');
        $this->actAsMcpTokenBoundTo($project);
        $settings = $this->seedSavedGraph($project, 'Settings', 0);
        $settings->metric = 'cost';
        $settings->unit = MetricUnit::Card;
        $settings->statistic = MetricStatistic::Sum;
        $this->em()->flush();
        $rename = $this->seedSavedGraph($project, 'Rename', 1);
        $resize = $this->seedSavedGraph($project, 'Resize', 2);
        $legend = $this->seedSavedGraph($project, 'Legend', 3);
        $move = $this->seedSavedGraph($project, 'Move', 4);
        $remove = $this->seedSavedGraph($project, 'Remove', 5);
        $code = $this->removalCode((string) $remove->id);
        $writes = [
            [$settings, fn () => $this->tool(GraphUpdateTool::class)((string) $settings->id, statistic: 'mean')],
            [$rename, fn () => $this->tool(GraphUpdateTool::class)((string) $rename->id, name: 'Renamed')],
            [$resize, fn () => $this->tool(GraphUpdateTool::class)((string) $resize->id, width: 'full')],
            [$legend, fn () => $this->tool(GraphUpdateTool::class)((string) $legend->id, showLegend: false)],
            [$move, fn () => $this->tool(GraphMoveTool::class)((string) $move->id, 0)],
            [$remove, fn () => $this->tool(GraphRemoveTool::class)((string) $remove->id, $code)],
        ];

        foreach ($writes as [$graph, $write]) {
            // The row changes behind the entity, so the finder still reads the graph as on show, as it does when the other session commits after that read.
            $this->em()->createQuery('UPDATE '.InsightsSavedGraph::class.' g SET g.removedAt = :now WHERE g.id = :id')
                ->setParameter('now', new \DateTimeImmutable())
                ->setParameter('id', $graph->id, UuidType::NAME)
                ->execute();

            try {
                $write();
                self::fail(\sprintf('Expected a refusal for %s.', $graph->name));
            } catch (ToolCallException $e) {
                self::assertSame(SavedGraphFinder::GONE, $e->getMessage(), $graph->name);
            }
        }
    }

    public function test_move_puts_the_graph_in_the_given_place(): void
    {
        $project = $this->scenarioProject('graphs-move');
        $a = $this->seedSavedGraph($project, 'A', 0);
        $this->seedSavedGraph($project, 'B', 1);
        $this->seedSavedGraph($project, 'C', 2);
        $this->actAsMcpTokenBoundTo($project);

        $toEnd = $this->tool(GraphMoveTool::class)((string) $a->id, 99);
        self::assertSame(['B', 'C', 'A'], array_column($toEnd['graphs'], 'name'));
        self::assertSame(2, $toEnd['graph']['position']);

        $back = $this->tool(GraphMoveTool::class)((string) $a->id, 0);
        self::assertSame(['A', 'B', 'C'], array_column($back['graphs'], 'name'));

        $same = $this->tool(GraphMoveTool::class)((string) $a->id, 0);
        self::assertSame(['A', 'B', 'C'], array_column($same['graphs'], 'name'));
    }

    public function test_move_refuses_a_negative_position(): void
    {
        $project = $this->scenarioProject('graphs-move-negative');
        $graph = $this->seedSavedGraph($project, 'A');
        $this->actAsMcpTokenBoundTo($project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('position: Use 0 for the first place');

        $this->tool(GraphMoveTool::class)((string) $graph->id, -1);
    }

    public function test_remove_previews_first_and_removes_with_the_code(): void
    {
        $project = $this->scenarioProject('graphs-remove');
        $graph = $this->seedSavedGraph($project, 'Tests');
        $this->actAsMcpTokenBoundTo($project);

        $preview = $this->tool(GraphRemoveTool::class)((string) $graph->id);
        if (!isset($preview['confirmation'])) {
            self::fail('Expected a preview.');
        }
        self::assertTrue($preview['preview']);
        self::assertSame('Tests', $preview['graph']['name']);
        self::assertCount(1, $this->tool(GraphListTool::class)()['graphs']);

        $removed = $this->tool(GraphRemoveTool::class)((string) $graph->id, $preview['confirmation']);

        if (!isset($removed['removedGraphId'])) {
            self::fail('Expected a removal.');
        }
        self::assertSame((string) $graph->id, $removed['removedGraphId']);
        self::assertSame([], $removed['graphs']);
        self::assertSame([(string) $graph->id], array_column($removed['removed'], 'id'));
        self::assertSame($removed['removed'][0]['undoUntil'], $removed['undoUntil']);
    }

    public function test_a_code_works_for_one_removal_only(): void
    {
        $project = $this->scenarioProject('graphs-remove-once');
        $graph = $this->seedSavedGraph($project, 'Tests');
        $this->actAsMcpTokenBoundTo($project);
        $code = $this->removalCode((string) $graph->id);
        $this->tool(GraphRemoveTool::class)((string) $graph->id, $code);
        $this->tool(GraphRestoreTool::class)((string) $graph->id);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('confirmation: This code does not fit the graph any more.');

        $this->tool(GraphRemoveTool::class)((string) $graph->id, $code);
    }

    public function test_a_web_removal_and_undo_end_the_code(): void
    {
        $project = $this->scenarioProject('graphs-remove-web');
        $graph = $this->seedSavedGraph($project, 'Tests');
        $this->actAsMcpTokenBoundTo($project);
        $code = $this->removalCode((string) $graph->id);

        $remove = self::getContainer()->get(RemoveSavedGraphHandler::class);
        $restore = self::getContainer()->get(RestoreSavedGraphHandler::class);
        self::assertInstanceOf(RemoveSavedGraphHandler::class, $remove);
        self::assertInstanceOf(RestoreSavedGraphHandler::class, $restore);
        $remove(new RemoveSavedGraphCommand($graph));
        $restore(new RestoreSavedGraphCommand($graph));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('confirmation: This code does not fit the graph any more.');

        $this->tool(GraphRemoveTool::class)((string) $graph->id, $code);
    }

    public function test_a_code_is_refused_after_the_graph_changed(): void
    {
        $project = $this->scenarioProject('graphs-remove-changed');
        $graph = $this->seedSavedGraph($project, 'Tests');
        $this->actAsMcpTokenBoundTo($project);
        $code = $this->removalCode((string) $graph->id);
        $this->tool(GraphUpdateTool::class)((string) $graph->id, name: 'Renamed');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('confirmation: This code does not fit the graph any more.');

        $this->tool(GraphRemoveTool::class)((string) $graph->id, $code);
    }

    /** @return iterable<string, array{string, string}> */
    public static function editsBehindTheEntity(): iterable
    {
        yield 'name' => ['name', 'Renamed'];
        yield 'metric' => ['metric', 'cost'];
        yield 'statistic' => ['statistic', 'mean'];
        yield 'group' => ['group', 'stage'];
        yield 'unit' => ['unit', 'card'];
        yield 'range' => ['range', 'seven-days'];
        yield 'bucket' => ['bucket', 'week'];
        yield 'compare' => ['compare', 'previous'];
        yield 'chart' => ['chartType', 'bars'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('editsBehindTheEntity')]
    public function test_a_code_is_refused_when_an_edit_commits_after_the_finder_read(string $field, string $value): void
    {
        $project = $this->scenarioProject('graphs-remove-race-'.$field);
        $graph = $this->seedSavedGraph($project, 'Tests');
        $this->actAsMcpTokenBoundTo($project);
        $code = $this->removalCode((string) $graph->id);

        // The row changes behind the entity, so the finder still reads the old values, as it does when the other session commits after that read.
        $this->em()->createQuery(\sprintf('UPDATE %s g SET g.%s = :value WHERE g.id = :id', InsightsSavedGraph::class, $field))
            ->setParameter('value', $value)
            ->setParameter('id', $graph->id, UuidType::NAME)
            ->execute();

        try {
            $this->tool(GraphRemoveTool::class)((string) $graph->id, $code);
            self::fail('Expected a refusal.');
        } catch (ToolCallException $e) {
            self::assertStringStartsWith('confirmation: This code does not fit the graph any more.', $e->getMessage());
        }
        self::assertSame([(string) $graph->id], array_column($this->tool(GraphListTool::class)()['graphs'], 'id'));
    }

    public function test_the_lock_reads_the_width_and_the_legend_again(): void
    {
        $graph = $this->seedSavedGraph($this->scenarioProject('graphs-lock-fields'), 'Tests');
        $this->em()->createQuery(\sprintf("UPDATE %s g SET g.width = 'full', g.showLegend = false WHERE g.id = :id", InsightsSavedGraph::class))
            ->setParameter('id', $graph->id, UuidType::NAME)
            ->execute();
        $locker = self::getContainer()->get(SavedGraphLocker::class);
        self::assertInstanceOf(SavedGraphLocker::class, $locker);

        $this->em()->wrapInTransaction(static fn (): bool => $locker->lockActive($graph));

        self::assertSame(SavedGraphWidth::Full, $graph->width);
        self::assertFalse($graph->showLegend);
    }

    public function test_a_wrong_code_removes_nothing(): void
    {
        $project = $this->scenarioProject('graphs-remove-wrong');
        $graph = $this->seedSavedGraph($project, 'Tests');
        $this->actAsMcpTokenBoundTo($project);
        $this->tool(GraphRemoveTool::class)((string) $graph->id);

        try {
            $this->tool(GraphRemoveTool::class)((string) $graph->id, 'abc');
            self::fail('Expected a refusal.');
        } catch (ToolCallException) {
            self::assertCount(1, $this->tool(GraphListTool::class)()['graphs']);
        }
    }

    public function test_a_worker_session_cannot_remove_a_graph(): void
    {
        $project = $this->scenarioProject('graphs-remove-worker');
        $graph = $this->seedSavedGraph($project, 'Tests');
        $this->actAsMcpTokenBoundTo($project);
        $worker = $this->createStub(WorkerSession::class);
        $worker->method('currentCallIsWorker')->willReturn(true);
        self::getContainer()->set(WorkerSession::class, $worker);

        try {
            $this->tool(GraphRemoveTool::class)((string) $graph->id);
            self::fail('Expected a refusal.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('A worker session cannot remove a graph', $e->getMessage());
        }
        self::assertCount(1, $this->tool(GraphListTool::class)()['graphs']);
    }

    public function test_restore_brings_a_removed_graph_back_to_its_place(): void
    {
        $project = $this->scenarioProject('graphs-restore');
        $this->seedSavedGraph($project, 'First', 0);
        $gone = $this->seedSavedGraph($project, 'Gone', 1, new \DateTimeImmutable('-1 hour'));
        $this->seedSavedGraph($project, 'Last', 2);
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->tool(GraphRestoreTool::class)((string) $gone->id);

        self::assertSame(['First', 'Gone', 'Last'], array_column($result['graphs'], 'name'));
        self::assertSame([], $result['removed']);
        self::assertSame($result, $this->tool(GraphRestoreTool::class)((string) $gone->id) + [], 'A graph that is back stays as it is.');
    }

    public function test_restore_refuses_a_full_dashboard_and_a_graph_past_the_window(): void
    {
        $project = $this->scenarioProject('graphs-restore-refused');
        $gone = $this->seedSavedGraph($project, 'Gone', 0, new \DateTimeImmutable('-1 hour'));
        $expired = $this->seedSavedGraph($project, 'Expired', 1, new \DateTimeImmutable('-3 days'));
        for ($i = 0; $i < SavedGraphCap::DEFAULT; ++$i) {
            $this->seedSavedGraph($project, 'Graph '.$i, 10 + $i);
        }
        $this->actAsMcpTokenBoundTo($project);

        try {
            $this->tool(GraphRestoreTool::class)((string) $gone->id);
            self::fail('Expected a refusal.');
        } catch (ToolCallException $e) {
            self::assertSame('The Dashboard holds the most graphs it can, 12. Remove a graph with graph_remove first.', $e->getMessage());
        }
        try {
            $this->tool(GraphRestoreTool::class)((string) $expired->id);
            self::fail('Expected a refusal.');
        } catch (ToolCallException $e) {
            self::assertSame('graphId: No saved graph of this project has this id. Read the ids with graph_list.', $e->getMessage());
        }
    }

    public function test_every_write_queues_the_dashboard_signal(): void
    {
        $project = $this->scenarioProject('graphs-signal');
        $a = $this->seedSavedGraph($project, 'A', 0);
        $this->seedSavedGraph($project, 'B', 1);
        $this->actAsMcpTokenBoundTo($project);
        $saved = $this->tool(GraphSaveTool::class)('cost', 'card', 'sum')['graph']['id'];

        $this->assertSignal('saved', fn () => $this->tool(GraphSaveTool::class)('cost', 'card', 'mean'));
        $this->assertSignal('updated', fn () => $this->tool(GraphUpdateTool::class)($saved, statistic: 'mean'));
        $this->assertSignal('renamed', fn () => $this->tool(GraphUpdateTool::class)($saved, name: 'Signal'));
        $this->assertSignal('resized', fn () => $this->tool(GraphUpdateTool::class)($saved, width: 'full'));
        $this->assertSignal('legend-changed', fn () => $this->tool(GraphUpdateTool::class)($saved, showLegend: false));
        $this->assertSignal('moved', fn () => $this->tool(GraphMoveTool::class)((string) $a->id, 1));
        $code = $this->removalCode($saved);
        $this->assertSignal('removed', fn () => $this->tool(GraphRemoveTool::class)($saved, $code));
        $this->assertSignal('restored', fn () => $this->tool(GraphRestoreTool::class)($saved));
    }

    /** The code of a graph_remove preview. */
    private function removalCode(string $graphId): string
    {
        $preview = $this->tool(GraphRemoveTool::class)($graphId);
        if (!isset($preview['confirmation'])) {
            self::fail('Expected a preview.');
        }

        return $preview['confirmation'];
    }

    private function assertSignal(string $kind, callable $write): void
    {
        $publisher = self::getContainer()->get(LiveUpdatePublisher::class);
        self::assertInstanceOf(LiveUpdatePublisher::class, $publisher);
        $pending = new \ReflectionProperty($publisher, 'pending');
        $pending->setValue($publisher, []);

        $write();

        $kinds = array_map(static fn (array $update): mixed => json_decode($update['data'], true)['kind'] ?? null, $pending->getValue($publisher));
        self::assertContains($kind, $kinds, \sprintf('The %s write queues its signal.', $kind));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function tool(string $class): object
    {
        $tool = self::getContainer()->get($class);
        self::assertInstanceOf($class, $tool);

        return $tool;
    }
}
