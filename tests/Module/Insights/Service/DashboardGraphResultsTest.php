<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Service;

use App\Module\Bridge\Metric\MetricCompare;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricGroupKey;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Entity\SavedGraphWidth;
use App\Module\Insights\Repository\InsightsKeptResultRepository;
use App\Module\Insights\Service\DashboardGraphResults;
use App\Module\Insights\Service\InsightsResultKeeper;
use App\Module\Insights\Service\KeptGraphView;
use App\Module\Insights\View\KeptGraphResult;
use App\Module\Insights\View\LineLayout;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DashboardGraphResultsTest extends KernelTestCase
{
    use InsightsScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_a_get_before_any_compute_finds_nothing_and_keeps_nothing(): void
    {
        $project = $this->scenarioProject('results-miss');
        $graph = $this->costGraph($project);

        self::assertNull($this->results()->get($graph));
        self::assertNull($this->kept()->findForGraph($graph));
    }

    public function test_a_compute_keeps_the_result_and_a_get_returns_an_equal_one(): void
    {
        $project = $this->scenarioProject('results-keep');
        $this->seedCosts($project);
        $graph = $this->costGraph($project);

        $computed = $this->keeper()->graph($graph);
        $kept = $this->results()->get($graph);

        self::assertNotNull($kept);
        self::assertEquals($computed, $kept);
        self::assertInstanceOf(LineLayout::class, $this->drawn($kept)->layout);
        self::assertNotNull($this->kept()->findForGraph($graph));
    }

    public function test_both_windows_count_back_from_the_one_clock_reading_the_result_records(): void
    {
        $project = $this->scenarioProject('results-clock');
        $graph = $this->costGraph($project);

        $result = $this->keeper()->graph($graph);

        self::assertEquals($result->computedAt, $result->current->to);
        self::assertNotNull($result->previous);
        self::assertEquals($result->current->from, $result->previous->to);
    }

    public function test_a_result_that_holds_no_row_has_no_layout(): void
    {
        $project = $this->scenarioProject('results-empty');

        $graph = $this->costGraph($project);

        $result = $this->keeper()->graph($graph);

        self::assertNull($this->drawn($result)->layout);
        self::assertNotNull($this->results()->get($graph));
    }

    public function test_the_result_keeps_the_key_of_each_series_in_the_order_of_the_labels(): void
    {
        $project = $this->scenarioProject('results-keys');
        $em = $this->em();
        $run = $this->seedRun($em, $project, cardNumber: 1, endedAt: new \DateTimeImmutable('-5 days'));
        $this->seedUsage($em, $run, costUsd: '3.00');
        $em->getConnection()->executeStatement('UPDATE bridge_worker_run_facts SET account = :account WHERE run_id = :run', ['account' => 'work', 'run' => (string) $run->id]);
        $graph = $this->costGraph($project);
        $graph->group = MetricGroup::Account;

        $result = $this->keeper()->graph($graph);

        self::assertSame(['work'], $this->drawn($result)->labels);
        self::assertEquals([new MetricGroupKey(account: 'work')], $this->drawn($result)->seriesKeys);
    }

    public function test_each_key_sits_at_the_position_of_its_label_when_the_total_order_differs_from_the_name_order(): void
    {
        $project = $this->scenarioProject('results-keys-order');
        $em = $this->em();
        foreach (['alpha' => '1.00', 'zeta' => '5.00'] as $account => $cost) {
            $run = $this->seedRun($em, $project, cardNumber: 'alpha' === $account ? 1 : 2, endedAt: new \DateTimeImmutable('-5 days'));
            $this->seedUsage($em, $run, costUsd: $cost);
            $em->getConnection()->executeStatement('UPDATE bridge_worker_run_facts SET account = :account WHERE run_id = :run', ['account' => $account, 'run' => (string) $run->id]);
        }
        $graph = $this->costGraph($project);
        $graph->group = MetricGroup::Account;

        $result = $this->keeper()->graph($graph);

        self::assertSame(['zeta', 'alpha'], $this->drawn($result)->labels);
        self::assertEquals([new MetricGroupKey(account: 'zeta'), new MetricGroupKey(account: 'alpha')], $this->drawn($result)->seriesKeys);
    }

    public function test_a_graph_with_no_split_has_null_keys(): void
    {
        $project = $this->scenarioProject('results-null-keys');
        $this->seedCosts($project);

        $result = $this->keeper()->graph($this->costGraph($project));

        $drawn = $this->drawn($result);
        self::assertNotSame([], $drawn->labels);
        self::assertSame(array_fill(0, \count($drawn->labels), null), $drawn->seriesKeys);
    }

    public function test_the_kept_result_holds_no_text_and_one_result_formats_in_each_language(): void
    {
        $project = $this->scenarioProject('results-language');
        $this->seedCosts($project);
        $graph = $this->costGraph($project);

        $kept = $this->keeper()->graph($graph);
        $english = $this->drawn($kept, 'en');
        $french = $this->drawn($kept, 'fr');

        self::assertNotSame(serialize($english->layout), serialize($french->layout));
        self::assertStringNotContainsString($english->title, serialize($kept));
    }

    public function test_a_row_that_fits_other_settings_counts_as_missing(): void
    {
        $project = $this->scenarioProject('results-stale-settings');
        $graph = $this->costGraph($project);
        $this->keeper()->graph($graph);
        self::assertNotNull($this->results()->get($graph));

        $graph->statistic = MetricStatistic::P90;

        self::assertNull($this->results()->get($graph));
    }

    public function test_a_rename_a_move_and_a_resize_keep_the_result(): void
    {
        $project = $this->scenarioProject('results-presentation');
        $graph = $this->costGraph($project);
        $this->keeper()->graph($graph);

        $graph->name = 'Another name';
        $graph->position = 9;
        $graph->width = SavedGraphWidth::Full;
        $graph->showLegend = false;

        self::assertNotNull($this->results()->get($graph));
    }

    public function test_a_result_never_reaches_a_graph_of_another_project(): void
    {
        $mine = $this->scenarioProject('results-mine');
        $theirs = $this->scenarioProject('results-theirs');
        $graph = $this->costGraph($mine);
        $this->keeper()->graph($graph);

        self::assertNull($this->results()->get($this->costGraph($theirs)));
    }

    public function test_for_graphs_returns_the_current_results_by_graph_id(): void
    {
        $project = $this->scenarioProject('results-many');
        $kept = $this->costGraph($project);
        $waiting = $this->seedSavedGraph($project, 'Waiting', 1);
        $this->keeper()->graph($kept);

        $results = $this->results()->forGraphs([$kept, $waiting]);

        self::assertSame([(string) $kept->id], array_keys($results));
    }

    public function test_a_payload_that_no_longer_reads_counts_as_missing(): void
    {
        $project = $this->scenarioProject('results-corrupt');
        $graph = $this->costGraph($project);
        $this->keeper()->graph($graph);
        $this->em()->getConnection()->executeStatement('UPDATE insights_kept_results SET payload = :payload WHERE saved_graph_id = :graph', ['payload' => base64_encode('O:8:"Missing_":0:{}'), 'graph' => (string) $graph->id]);
        $this->em()->clear();

        self::assertNull($this->results()->get($this->em()->find(InsightsSavedGraph::class, $graph->id) ?? $graph));
    }

    public function test_a_second_keep_replaces_the_row_and_leaves_one(): void
    {
        $project = $this->scenarioProject('results-replace');
        $graph = $this->costGraph($project);

        $this->keeper()->graph($graph);
        $first = $this->kept()->findForGraph($graph)?->computedAt;
        usleep(1_100_000);
        $this->keeper()->graph($graph);

        $count = $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM insights_kept_results WHERE saved_graph_id = :graph', ['graph' => (string) $graph->id]);
        self::assertSame(1, (int) $count);
        $second = $this->kept()->findForGraph($graph)?->computedAt;
        self::assertNotNull($first);
        self::assertGreaterThan($first, $second);
    }

    private function drawn(KeptGraphResult $kept, string $locale = 'en'): \App\Module\Insights\View\DashboardGraphResult
    {
        $view = self::getContainer()->get(KeptGraphView::class);
        self::assertInstanceOf(KeptGraphView::class, $view);

        return $view->build($kept, $locale);
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
        $results = self::getContainer()->get(DashboardGraphResults::class);
        self::assertInstanceOf(DashboardGraphResults::class, $results);

        return $results;
    }

    private function keeper(): InsightsResultKeeper
    {
        $keeper = self::getContainer()->get(InsightsResultKeeper::class);
        self::assertInstanceOf(InsightsResultKeeper::class, $keeper);

        return $keeper;
    }

    private function kept(): InsightsKeptResultRepository
    {
        $kept = self::getContainer()->get(InsightsKeptResultRepository::class);
        self::assertInstanceOf(InsightsKeptResultRepository::class, $kept);

        return $kept;
    }
}
