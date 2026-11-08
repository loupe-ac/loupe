<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Messenger;

use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Messenger\RefreshProjectInsights;
use App\Module\Insights\Messenger\RefreshProjectInsightsHandler;
use App\Module\Insights\Service\InsightsDataKind;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class RefreshProjectInsightsHandlerTest extends KernelTestCase
{
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
