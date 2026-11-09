<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Service;

use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Insights\Service\InsightsDataKind;
use App\Module\Insights\Service\InsightsDataKinds;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InsightsDataKindsTest extends KernelTestCase
{
    use InsightsScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_a_run_graph_of_a_plain_metric_reads_runs_only(): void
    {
        self::assertSame([InsightsDataKind::Runs], $this->readsOf('cost', MetricUnit::Run));
    }

    public function test_a_card_graph_of_a_plain_metric_reads_runs_and_cards(): void
    {
        self::assertSame([InsightsDataKind::Runs, InsightsDataKind::Cards], $this->readsOf('cost', MetricUnit::Card));
    }

    public function test_a_bucket_graph_reads_the_bucket_times_too(): void
    {
        self::assertSame([InsightsDataKind::Runs, InsightsDataKind::BucketTimes], $this->readsOf('bucket-time:tests', MetricUnit::Run));
        self::assertSame([InsightsDataKind::Runs, InsightsDataKind::BucketTimes], $this->readsOf('bucket-calls-each', MetricUnit::Run));
    }

    public function test_an_account_usage_graph_reads_readings_only(): void
    {
        self::assertSame([InsightsDataKind::Readings], $this->readsOf('account-usage', MetricUnit::Reading));
    }

    public function test_a_shared_experiment_reads_the_kinds_of_its_cards_and_runs(): void
    {
        $kinds = self::getContainer()->get(InsightsDataKinds::class);
        self::assertInstanceOf(InsightsDataKinds::class, $kinds);

        self::assertTrue($kinds->experimentReads(InsightsDataKind::Runs));
        self::assertTrue($kinds->experimentReads(InsightsDataKind::BucketTimes));
        self::assertTrue($kinds->experimentReads(InsightsDataKind::Cards));
        self::assertFalse($kinds->experimentReads(InsightsDataKind::Readings));
    }

    public function test_the_sweep_keeps_a_rolling_range_and_a_template_metric_fresh(): void
    {
        $graph = $this->seedSavedGraph($this->scenarioProject('kinds-sweep-'.uniqid()));
        $kinds = self::getContainer()->get(InsightsDataKinds::class);
        self::assertInstanceOf(InsightsDataKinds::class, $kinds);

        self::assertTrue($kinds->sweeps($graph));

        $graph->range = MetricRange::All;
        $graph->metric = 'cost';
        self::assertFalse($kinds->sweeps($graph));

        $graph->metric = 'review-rounds';
        self::assertTrue($kinds->sweeps($graph));
    }

    public function test_a_formula_graph_reads_the_union_of_its_rows(): void
    {
        $kinds = self::getContainer()->get(InsightsDataKinds::class);
        self::assertInstanceOf(InsightsDataKinds::class, $kinds);
        $project = $this->scenarioProject('kinds-formula-'.uniqid());

        $plain = $this->seedFormulaGraph($project);
        self::assertSame([InsightsDataKind::Runs, InsightsDataKind::Cards], $kinds->reads($plain));

        $buckets = $this->seedFormulaGraph($project, 'A:cost:sum,B:bucket-time:tests:sum', 'A / B', position: 1);
        self::assertSame([InsightsDataKind::Runs, InsightsDataKind::Cards, InsightsDataKind::BucketTimes], $kinds->reads($buckets));

        $buckets->unit = MetricUnit::Run;
        self::assertSame([InsightsDataKind::Runs, InsightsDataKind::BucketTimes], $kinds->reads($buckets));
    }

    public function test_a_formula_graph_sweeps_when_any_row_is_not_built_in_or_the_range_rolls(): void
    {
        $kinds = self::getContainer()->get(InsightsDataKinds::class);
        self::assertInstanceOf(InsightsDataKinds::class, $kinds);
        $graph = $this->seedFormulaGraph($this->scenarioProject('kinds-formula-sweep-'.uniqid()));

        self::assertTrue($kinds->sweeps($graph));

        $graph->range = MetricRange::All;
        self::assertFalse($kinds->sweeps($graph));

        $graph->formulaRows = 'A:cache-read-tokens:sum,B:review-rounds:sum';
        self::assertTrue($kinds->sweeps($graph));
    }

    /** @return list<InsightsDataKind> */
    private function readsOf(string $metric, MetricUnit $unit): array
    {
        $graph = $this->seedSavedGraph($this->scenarioProject('kinds-'.uniqid()));
        $graph->metric = $metric;
        $graph->unit = $unit;

        $kinds = self::getContainer()->get(InsightsDataKinds::class);
        self::assertInstanceOf(InsightsDataKinds::class, $kinds);

        return $kinds->reads($graph);
    }
}
