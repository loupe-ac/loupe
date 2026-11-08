<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Repository;

use App\Module\Bridge\Metric\MetricRange;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Repository\InsightsProjectActivityRepository;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InsightsProjectActivityRepositoryTest extends KernelTestCase
{
    use InsightsScenario;

    private const array BUILT_IN = ['cost', 'bucket-time'];

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_a_project_with_no_row_is_dormant(): void
    {
        $project = $this->scenarioProject('activity-none');

        self::assertFalse($this->repository()->isAwake((string) $project->id, new \DateTimeImmutable(), 7));
    }

    public function test_a_view_or_new_data_wakes_the_project_for_the_dormant_period(): void
    {
        $now = new \DateTimeImmutable('2026-11-07 12:00:00');
        $viewed = $this->scenarioProject('activity-view');
        $fed = $this->scenarioProject('activity-data');

        $this->repository()->stampView((string) $viewed->id, $now->modify('-6 days'));
        $this->repository()->stampData((string) $fed->id, $now->modify('-8 days'));

        self::assertTrue($this->repository()->isAwake((string) $viewed->id, $now, 7));
        self::assertFalse($this->repository()->isAwake((string) $viewed->id, $now, 5));
        self::assertFalse($this->repository()->isAwake((string) $fed->id, $now, 7));
    }

    public function test_the_later_of_the_two_stamps_counts(): void
    {
        $now = new \DateTimeImmutable('2026-11-07 12:00:00');
        $project = $this->scenarioProject('activity-both');

        $this->repository()->stampView((string) $project->id, $now->modify('-20 days'));
        $this->repository()->stampData((string) $project->id, $now->modify('-1 day'));

        self::assertTrue($this->repository()->isAwake((string) $project->id, $now, 7));
    }

    public function test_a_stamp_is_written_at_most_once_an_hour(): void
    {
        $now = new \DateTimeImmutable('2026-11-07 12:00:00');
        $project = $this->scenarioProject('activity-limit');
        $id = (string) $project->id;

        $this->repository()->stampView($id, $now);
        $this->repository()->stampView($id, $now->modify('+30 minutes'));
        self::assertSame('2026-11-07 12:00:00', $this->column('viewed_at', $id));

        $this->repository()->stampView($id, $now->modify('+61 minutes'));
        self::assertSame('2026-11-07 13:01:00', $this->column('viewed_at', $id));
    }

    public function test_the_two_stamps_do_not_touch_each_other(): void
    {
        $now = new \DateTimeImmutable('2026-11-07 12:00:00');
        $project = $this->scenarioProject('activity-apart');
        $id = (string) $project->id;

        $this->repository()->stampView($id, $now);

        self::assertNull($this->column('data_at', $id));
    }

    public function test_the_sweep_picks_an_awake_project_with_a_stale_rolling_graph(): void
    {
        $now = new \DateTimeImmutable('2026-11-07 12:00:00');
        $project = $this->scenarioProject('sweep-rolling');
        $this->seedSavedGraph($project);
        $this->repository()->stampView((string) $project->id, $now);

        self::assertContains((string) $project->id, $this->sweep($now));
    }

    public function test_the_sweep_skips_a_dormant_project(): void
    {
        $now = new \DateTimeImmutable('2026-11-07 12:00:00');
        $project = $this->scenarioProject('sweep-dormant');
        $this->seedSavedGraph($project);
        $this->repository()->stampView((string) $project->id, $now->modify('-8 days'));

        self::assertNotContains((string) $project->id, $this->sweep($now));
    }

    public function test_the_sweep_skips_a_fresh_result(): void
    {
        $now = new \DateTimeImmutable('2026-11-07 12:00:00');
        $project = $this->scenarioProject('sweep-fresh');
        $graph = $this->seedSavedGraph($project);
        $this->repository()->stampView((string) $project->id, $now);
        $this->keepGraph($graph->id?->toRfc4122(), (string) $project->id, $now->modify('-5 minutes'));

        self::assertNotContains((string) $project->id, $this->sweep($now));

        $this->em()->getConnection()->executeStatement('UPDATE insights_kept_results SET computed_at = :at', ['at' => $now->modify('-16 minutes')->format('Y-m-d H:i:s')]);

        self::assertContains((string) $project->id, $this->sweep($now));
    }

    public function test_the_sweep_skips_the_range_all_of_a_built_in_metric_but_not_of_a_template_metric(): void
    {
        $now = new \DateTimeImmutable('2026-11-07 12:00:00');
        $builtIn = $this->scenarioProject('sweep-all-built-in');
        $graph = $this->seedSavedGraph($builtIn);
        $graph->range = MetricRange::All;
        $graph->metric = 'cost';
        $template = $this->scenarioProject('sweep-all-template');
        $other = $this->seedSavedGraph($template);
        $other->range = MetricRange::All;
        $other->metric = 'review-rounds';
        $this->em()->flush();
        $this->repository()->stampView((string) $builtIn->id, $now);
        $this->repository()->stampView((string) $template->id, $now);

        $found = $this->sweep($now);

        self::assertNotContains((string) $builtIn->id, $found);
        self::assertContains((string) $template->id, $found);
    }

    public function test_the_sweep_picks_a_project_with_a_stale_shared_experiment(): void
    {
        $now = new \DateTimeImmutable('2026-11-07 12:00:00');
        $project = $this->scenarioProject('sweep-experiment');
        $this->seedExperiment($project, 'trial');
        $this->em()->persist(PublicLink::forExperiment($project, 'trial'));
        $this->em()->flush();
        $this->repository()->stampView((string) $project->id, $now);

        self::assertContains((string) $project->id, $this->sweep($now));
    }

    public function test_the_sweep_skips_a_removed_graph(): void
    {
        $now = new \DateTimeImmutable('2026-11-07 12:00:00');
        $project = $this->scenarioProject('sweep-removed');
        $this->seedSavedGraph($project, 'Gone', 0, new \DateTimeImmutable('-1 minute'));
        $this->repository()->stampView((string) $project->id, $now);

        self::assertNotContains((string) $project->id, $this->sweep($now));
    }

    /** @return list<string> */
    private function sweep(\DateTimeImmutable $now): array
    {
        return $this->repository()->findToSweep($now, 7, 15, self::BUILT_IN);
    }

    private function keepGraph(?string $graphId, string $projectId, \DateTimeImmutable $computedAt): void
    {
        $this->em()->getConnection()->executeStatement(
            "INSERT INTO insights_kept_results (id, project_id, saved_graph_id, experiment, settings_hash, computed_at, payload) VALUES (gen_random_uuid(), :project, :graph, NULL, 'x', :at, 'x')",
            ['project' => $projectId, 'graph' => $graphId, 'at' => $computedAt->format('Y-m-d H:i:s')],
        );
    }

    private function column(string $column, string $projectId): mixed
    {
        $value = $this->em()->getConnection()->fetchOne('SELECT '.$column.' FROM insights_project_activity WHERE project_id = :id', ['id' => $projectId]);

        return false === $value ? null : $value;
    }

    private function repository(): InsightsProjectActivityRepository
    {
        $repository = self::getContainer()->get(InsightsProjectActivityRepository::class);
        self::assertInstanceOf(InsightsProjectActivityRepository::class, $repository);

        return $repository;
    }
}
