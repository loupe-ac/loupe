<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Service;

use App\Module\Bridge\Metric\MetricCompare;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Insights\Command\ShowMetricsHandler;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Entity\SavedGraphWidth;
use App\Module\Insights\Service\DashboardGraphResults;
use App\Module\Insights\View\LineLayout;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\TemplateMetrics;
use App\Tests\Module\Insights\InsightsScenario;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\ItemInterface;

final class DashboardGraphResultsTest extends KernelTestCase
{
    use InsightsScenario;

    private ArrayAdapter $pool;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->pool = new ArrayAdapter();
    }

    public function test_a_get_before_any_compute_finds_nothing_and_keeps_nothing(): void
    {
        $project = $this->scenarioProject('results-miss');
        $graph = $this->costGraph($project);

        self::assertNull($this->results()->get($project, $graph));
        self::assertFalse($this->pool->hasItem($this->results()->key($project, $graph)));
    }

    public function test_a_compute_keeps_the_result_and_a_get_returns_an_equal_one(): void
    {
        $project = $this->scenarioProject('results-keep');
        $this->seedCosts($project);
        $graph = $this->costGraph($project);

        $computed = $this->results()->compute($project, $graph);
        $kept = $this->results()->get($project, $graph);

        self::assertNotNull($kept);
        self::assertEquals($computed, $kept);
        self::assertInstanceOf(LineLayout::class, $kept->layout);
        self::assertCount(1, $this->pool->getValues());
    }

    public function test_both_windows_count_back_from_the_one_clock_reading_the_result_records(): void
    {
        $project = $this->scenarioProject('results-clock');
        $graph = $this->costGraph($project);

        $result = $this->results()->compute($project, $graph);

        self::assertEquals($result->computedAt, $result->current->to);
        self::assertNotNull($result->previous);
        self::assertEquals($result->current->from, $result->previous->to);
    }

    public function test_a_result_that_holds_no_row_has_no_layout(): void
    {
        $project = $this->scenarioProject('results-empty');

        $result = $this->results()->compute($project, $this->costGraph($project));

        self::assertNull($result->layout);
        self::assertNotNull($this->results()->get($project, $this->costGraph($project)));
    }

    public function test_the_key_ignores_the_name_the_position_the_width_and_the_legend(): void
    {
        $project = $this->scenarioProject('results-key-presentation');
        $graph = $this->costGraph($project);
        $key = $this->results()->key($project, $graph);

        $graph->name = 'Another name';
        $graph->position = 9;
        $graph->width = SavedGraphWidth::Full;
        $graph->showLegend = false;

        self::assertSame($key, $this->results()->key($project, $graph));
    }

    public function test_an_edit_of_any_setting_changes_the_key(): void
    {
        $project = $this->scenarioProject('results-key-settings');
        $graph = $this->costGraph($project);
        $key = $this->results()->key($project, $graph);

        $graph->statistic = MetricStatistic::P90;
        self::assertNotSame($key, $this->results()->key($project, $graph));

        $graph->statistic = MetricStatistic::Median;
        self::assertSame($key, $this->results()->key($project, $graph));
        $graph->compare = MetricCompare::Off;
        self::assertNotSame($key, $this->results()->key($project, $graph));
    }

    public function test_the_key_holds_the_project_id_so_projects_never_share_a_result(): void
    {
        $mine = $this->scenarioProject('results-key-mine');
        $theirs = $this->scenarioProject('results-key-theirs');
        $graph = $this->costGraph($mine);

        self::assertStringContainsString((string) $mine->id, $this->results()->key($mine, $graph));
        self::assertNotSame($this->results()->key($mine, $graph), $this->results()->key($theirs, $graph));

        $this->results()->compute($mine, $graph);
        self::assertNull($this->results()->get($theirs, $graph));
    }

    public function test_a_result_is_kept_for_fifteen_minutes(): void
    {
        $project = $this->scenarioProject('results-ttl');
        $graph = $this->costGraph($project);

        $before = time();
        $this->results()->compute($project, $graph);

        $item = $this->pool->getItem($this->results()->key($project, $graph));
        self::assertTrue($item->isHit());
        self::assertEqualsWithDelta($before + DashboardGraphResults::LIFETIME, $item->getMetadata()[ItemInterface::METADATA_EXPIRY] ?? null, 5);
    }

    private function costGraph(Project $project): InsightsSavedGraph
    {
        $graph = $this->seedSavedGraph($project, 'Cost', 0);
        $graph->metric = 'cost';
        $graph->unit = MetricUnit::Run;
        $graph->statistic = MetricStatistic::Median;
        $graph->range = MetricRange::ThirtyDays;
        $graph->compare = MetricCompare::Previous;

        return $graph;
    }

    private function seedCosts(Project $project): void
    {
        $em = $this->em();
        $this->seedUsage($em, $this->seedRun($em, $project, cardNumber: 1, endedAt: new \DateTimeImmutable('-5 days')), costUsd: '3.00');
        $this->seedUsage($em, $this->seedRun($em, $project, cardNumber: 2, endedAt: new \DateTimeImmutable('-40 days')), costUsd: '2.00');
    }

    private function results(): DashboardGraphResults
    {
        $container = self::getContainer();
        $showMetrics = $container->get(ShowMetricsHandler::class);
        $templates = $container->get(TemplateMetrics::class);
        $clock = $container->get(ClockInterface::class);
        self::assertInstanceOf(ShowMetricsHandler::class, $showMetrics);
        self::assertInstanceOf(TemplateMetrics::class, $templates);
        self::assertInstanceOf(ClockInterface::class, $clock);

        return new DashboardGraphResults($this->pool, $showMetrics, $templates, $clock);
    }
}
