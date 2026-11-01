<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Service;

use App\Module\Insights\Entity\InsightsBucketRule;
use App\Module\Insights\Service\SavedGraphHealth;
use App\Module\Insights\View\BrokenGraphReason;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SavedGraphHealthTest extends KernelTestCase
{
    use InsightsScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_a_built_in_metric_is_never_broken(): void
    {
        $project = $this->scenarioProject('health-builtin');
        $graph = $this->seedSavedGraph($project, 'Cost', 0);
        $graph->metric = 'cost';

        self::assertSame([], $this->health()->check($project, [$graph]));
    }

    public function test_a_bucket_that_no_rule_names_is_broken_and_a_bucket_with_a_rule_is_not(): void
    {
        $project = $this->scenarioProject('health-bucket');
        $graph = $this->seedSavedGraph($project, 'Tests', 0);

        $broken = $this->health()->check($project, [$graph]);

        self::assertSame([(string) $graph->id], array_keys($broken));
        self::assertSame(BrokenGraphReason::Bucket, $broken[(string) $graph->id]->reason);
        self::assertSame('tests', $broken[(string) $graph->id]->name);

        $this->em()->persist(new InsightsBucketRule($project, 'Bash:*', 'tests', 0));
        $this->em()->flush();

        self::assertSame([], $this->health()->check($project, [$graph]));
    }

    public function test_a_bucket_that_runs_still_carry_is_broken_when_the_rule_is_gone(): void
    {
        $project = $this->scenarioProject('health-old-runs');
        $run = $this->seedRun($this->em(), $project);
        $this->seedBucketTimes($this->em(), $run, ['tests' => 5000]);
        $graph = $this->seedSavedGraph($project, 'Tests', 0);

        $broken = $this->health()->check($project, [$graph]);

        self::assertSame(BrokenGraphReason::Bucket, $broken[(string) $graph->id]->reason ?? null);
    }

    public function test_a_harness_event_kind_that_no_run_records_is_broken_and_a_recorded_kind_is_not(): void
    {
        $project = $this->scenarioProject('health-event');
        $graph = $this->seedSavedGraph($project, 'Compactions', 0);
        $graph->metric = 'harness-events:compaction';

        $broken = $this->health()->check($project, [$graph]);
        self::assertSame(BrokenGraphReason::HarnessEvent, $broken[(string) $graph->id]->reason ?? null);
        self::assertSame('compaction', $broken[(string) $graph->id]->name ?? null);

        $run = $this->seedRun($this->em(), $project);
        $run->harnessEvents = ['compaction' => 2];
        $this->em()->flush();

        self::assertSame([], $this->health()->check($project, [$graph]));
    }

    public function test_a_template_metric_that_the_workflow_no_longer_declares_is_broken(): void
    {
        $project = $this->scenarioProject('health-template');
        $graph = $this->seedSavedGraph($project, 'Lead time', 0);
        $graph->metric = 'lead_time';

        $broken = $this->health()->check($project, [$graph]);

        self::assertSame(BrokenGraphReason::TemplateMetric, $broken[(string) $graph->id]->reason ?? null);
        self::assertSame('lead_time', $broken[(string) $graph->id]->name ?? null);
    }

    public function test_only_the_broken_graphs_are_listed(): void
    {
        $project = $this->scenarioProject('health-mixed');
        $fine = $this->seedSavedGraph($project, 'Cost', 0);
        $fine->metric = 'cost';
        $broken = $this->seedSavedGraph($project, 'Tests', 1);

        self::assertSame([(string) $broken->id], array_keys($this->health()->check($project, [$fine, $broken])));
    }

    private function health(): SavedGraphHealth
    {
        $health = self::getContainer()->get(SavedGraphHealth::class);
        self::assertInstanceOf(SavedGraphHealth::class, $health);

        return $health;
    }
}
