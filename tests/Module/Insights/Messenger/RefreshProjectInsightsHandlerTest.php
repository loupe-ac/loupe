<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Messenger;

use App\Module\Board\Entity\Card;
use App\Module\Bridge\Metric\MetricCompare;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Messenger\RefreshProjectInsights;
use App\Module\Insights\Messenger\RefreshProjectInsightsHandler;
use App\Module\Insights\Service\InsightsDataKind;
use App\Module\Workflow\Contract\TemplateMetrics;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class RefreshProjectInsightsHandlerTest extends KernelTestCase
{
    use BoardColumnFixtures;
    use InsightsScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_a_kind_refreshes_only_the_graphs_that_read_it(): void
    {
        $project = $this->scenarioProject('project-refresh-kind');
        $run = $this->seedSavedGraph($project, 'Cost', 0);
        $run->metric = 'cost';
        $reading = $this->seedSavedGraph($project, 'Usage', 1);
        $reading->metric = 'account-usage';
        $reading->unit = MetricUnit::Reading;
        $this->em()->flush();
        $projectId = (string) $project->id;
        $runId = $run->id;
        $readingId = $reading->id;
        $this->em()->clear();

        $this->handler()(new RefreshProjectInsights($projectId, InsightsDataKind::Runs->value));

        self::assertSame(1, $this->keptRows());
        self::assertSame(1, $this->keptFor($runId));
        self::assertSame(0, $this->keptFor($readingId));
    }

    public function test_a_removed_graph_keeps_nothing(): void
    {
        $project = $this->scenarioProject('project-refresh-removed');
        $this->seedSavedGraph($project, 'Gone', 0, new \DateTimeImmutable('-1 minute'));
        $projectId = (string) $project->id;
        $this->em()->clear();

        $this->handler()(new RefreshProjectInsights($projectId, InsightsDataKind::Runs->value));

        self::assertSame(0, $this->keptRows());
    }

    public function test_a_kind_of_runs_refreshes_a_shared_experiment(): void
    {
        $project = $this->scenarioProject('project-refresh-experiment');
        $this->seedExperiment($project, 'trial');
        $this->em()->persist(PublicLink::forExperiment($project, 'trial'));
        $this->em()->flush();
        $projectId = (string) $project->id;
        $this->em()->clear();

        $this->handler()(new RefreshProjectInsights($projectId, InsightsDataKind::Runs->value));

        self::assertSame(1, $this->keptRows());
    }

    public function test_the_kind_experiment_refreshes_the_named_experiment_alone(): void
    {
        $project = $this->scenarioProject('project-refresh-named');
        $this->seedExperiment($project, 'trial');
        $this->em()->persist(PublicLink::forExperiment($project, 'trial'));
        $graph = $this->seedSavedGraph($project, 'Cost', 0);
        $graph->metric = 'cost';
        $this->em()->flush();
        $projectId = (string) $project->id;
        $this->em()->clear();

        $this->handler()(new RefreshProjectInsights($projectId, InsightsDataKind::Experiment->value, 'trial'));

        self::assertSame(1, $this->keptRows());
        self::assertSame(0, $this->keptFor($graph->id));
    }

    public function test_an_unknown_kind_or_project_does_nothing(): void
    {
        $project = $this->scenarioProject('project-refresh-unknown');
        $this->seedSavedGraph($project, 'Cost', 0);
        $projectId = (string) $project->id;
        $this->em()->clear();

        $this->handler()(new RefreshProjectInsights($projectId, 'nothing'));
        $this->handler()(new RefreshProjectInsights('00000000-0000-7000-8000-000000000000', InsightsDataKind::Runs->value));

        self::assertSame(0, $this->keptRows());
    }

    public function test_a_data_kind_stamps_the_data_time_of_the_project(): void
    {
        $project = $this->scenarioProject('project-refresh-stamp');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $this->handler()(new RefreshProjectInsights($projectId, InsightsDataKind::Runs->value));

        self::assertSame(1, $this->activityRows($projectId, 'data_at'));
    }

    public function test_the_sweep_and_a_wake_never_stamp_the_data_time(): void
    {
        $project = $this->scenarioProject('project-refresh-no-stamp');
        $this->seedSavedGraph($project, 'Cost', 0);
        $projectId = (string) $project->id;
        $this->em()->clear();

        $this->handler()(new RefreshProjectInsights($projectId, InsightsDataKind::Sweep->value));
        $this->handler()(new RefreshProjectInsights($projectId, InsightsDataKind::Wake->value));

        self::assertSame(0, $this->activityRows($projectId, 'data_at'));
    }

    public function test_the_sweep_refreshes_a_rolling_graph_whose_result_is_old_or_missing(): void
    {
        $project = $this->scenarioProject('project-refresh-sweep');
        $rolling = $this->seedSavedGraph($project, 'Rolling', 0);
        $rolling->metric = 'cost';
        $all = $this->seedSavedGraph($project, 'All', 1);
        $all->metric = 'cost';
        $all->range = MetricRange::All;
        $this->em()->flush();
        $projectId = (string) $project->id;
        $rollingId = $rolling->id;
        $allId = $all->id;
        $this->em()->clear();

        $this->handler()(new RefreshProjectInsights($projectId, InsightsDataKind::Sweep->value));

        self::assertSame(1, $this->keptFor($rollingId));
        self::assertSame(0, $this->keptFor($allId));
    }

    public function test_the_sweep_leaves_a_fresh_result_alone(): void
    {
        $project = $this->scenarioProject('project-refresh-sweep-fresh');
        $graph = $this->seedSavedGraph($project, 'Rolling', 0);
        $graph->metric = 'cost';
        $this->em()->flush();
        $projectId = (string) $project->id;
        $graphId = $graph->id;
        $this->em()->clear();
        $this->handler()(new RefreshProjectInsights($projectId, InsightsDataKind::Wake->value));
        $first = $this->computedAt($graphId);

        $this->handler()(new RefreshProjectInsights($projectId, InsightsDataKind::Sweep->value));

        self::assertSame($first, $this->computedAt($graphId));
    }

    public function test_a_wake_refreshes_every_graph_and_shared_experiment(): void
    {
        $project = $this->scenarioProject('project-refresh-wake');
        $all = $this->seedSavedGraph($project, 'All', 0);
        $all->metric = 'cost';
        $all->range = MetricRange::All;
        $this->seedExperiment($project, 'trial');
        $this->em()->persist(PublicLink::forExperiment($project, 'trial'));
        $this->em()->flush();
        $projectId = (string) $project->id;
        $this->em()->clear();

        $this->handler()(new RefreshProjectInsights($projectId, InsightsDataKind::Wake->value));

        self::assertSame(2, $this->keptRows());
    }

    public function test_a_refresh_of_five_graphs_loads_the_facts_twice_and_the_finished_cards_once(): void
    {
        $project = $this->scenarioProject('project-refresh-loads');
        $this->seedColumns($project);
        $this->em()->flush();
        $card = new Card(project: $project, column: $this->column($project, 'done'), title: 'Done', body: '', number: 1, type: 'feature', position: 0);
        $card->completedAt = new \DateTimeImmutable('-2 days');
        $this->em()->persist($card);
        $this->em()->flush();
        $this->seedRun($this->em(), $project, cardId: $card->id, endedAt: new \DateTimeImmutable('-3 days'));
        foreach ([
            [MetricUnit::Card, MetricRange::ThirtyDays, MetricCompare::Previous],
            [MetricUnit::Card, MetricRange::SevenDays, MetricCompare::Previous],
            [MetricUnit::Run, MetricRange::ThirtyDays, MetricCompare::Previous],
            [MetricUnit::Run, MetricRange::NinetyDays, MetricCompare::Off],
            [MetricUnit::Run, MetricRange::NinetyDays, MetricCompare::Off],
        ] as $position => [$unit, $range, $compare]) {
            $graph = $this->seedSavedGraph($project, 'Cost '.$position, $position);
            $graph->metric = 'cost';
            $graph->unit = $unit;
            $graph->range = $range;
            $graph->compare = $compare;
        }
        $this->em()->flush();
        $projectId = (string) $project->id;
        $this->em()->clear();

        $statements = $this->statementsOf(fn () => $this->handler()(new RefreshProjectInsights($projectId, InsightsDataKind::Runs->value)));

        self::assertSame(5, $this->keptRows());
        self::assertCount(2, array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'bridge_worker_run_facts')));
        self::assertCount(1, array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'completed_at IS NOT NULL')));
    }

    public function test_a_template_change_saved_before_the_lock_of_a_graph_reaches_its_health_check_and_its_query(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        self::getContainer()->set(TemplateMetrics::class, $reads = new ScriptedTemplateMetrics());
        $project = $this->scenarioProject('project-refresh-template');
        $graph = $this->seedSavedGraph($project, 'Rework', 0);
        $graph->metric = 'rework';
        $graph->unit = MetricUnit::Card;
        $graph->statistic = MetricStatistic::Count;
        $this->em()->flush();
        $projectId = (string) $project->id;
        $graphId = $graph->id;
        $this->em()->clear();

        // The metric is gone when the graph takes its lock: the health check at the lock skips it.
        $this->handler()(new RefreshProjectInsights($projectId, InsightsDataKind::Wake->value));

        self::assertSame(0, $this->keptFor($graphId));
        self::assertSame(2, $reads->calls);

        // The label changed after the batch read: the query at the lock parses against the set of the lock.
        $reads->calls = 0;
        $reads->later = [ScriptedTemplateMetrics::declaring('After')];
        $this->handler()(new RefreshProjectInsights($projectId, InsightsDataKind::Wake->value));

        self::assertSame(1, $this->keptFor($graphId));
        self::assertSame(2, $reads->calls);
        self::assertStringContainsString('After', (string) base64_decode((string) $this->em()->getConnection()->fetchOne('SELECT payload FROM insights_kept_results WHERE saved_graph_id = :id', ['id' => $graphId], ['id' => UuidType::NAME])));
    }

    /** @return list<string> */
    private function statementsOf(callable $run): array
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $holder->reset();
        $run();

        $statements = [];
        foreach ($holder->getData() as $queries) {
            foreach ($queries as $query) {
                $statements[] = (string) $query['sql'];
            }
        }
        // Guard: the counts mean nothing when the refresh ran no query.
        self::assertNotEmpty($statements);

        return $statements;
    }

    private function computedAt(?Uuid $graphId): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT computed_at FROM insights_kept_results WHERE saved_graph_id = :id', ['id' => $graphId], ['id' => UuidType::NAME]);
    }

    private function activityRows(string $projectId, string $column): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM insights_project_activity WHERE project_id = :id AND '.$column.' IS NOT NULL', ['id' => $projectId]);
    }

    private function keptRows(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM insights_kept_results');
    }

    private function keptFor(?Uuid $graphId): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM insights_kept_results WHERE saved_graph_id = :id', ['id' => $graphId], ['id' => UuidType::NAME]);
    }

    private function handler(): RefreshProjectInsightsHandler
    {
        $handler = self::getContainer()->get(RefreshProjectInsightsHandler::class);
        self::assertInstanceOf(RefreshProjectInsightsHandler::class, $handler);

        return $handler;
    }
}
