<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Service;

use App\Module\Bridge\Metric\MetricCompare;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Repository\PublicSeriesLabelRepository;
use App\Module\Insights\Service\DashboardGraphResults;
use App\Module\Insights\Service\InsightsRefreshSettings;
use App\Module\Insights\Service\InsightsResultKeeper;
use App\Module\Insights\Service\KeptGraphView;
use App\Module\Insights\Service\PublicGraphResults;
use App\Module\Insights\Service\PublicSeriesLabels;
use App\Module\Insights\Service\SavedGraphHealth;
use App\Module\Insights\View\ChartBox;
use App\Module\Insights\View\LineLayout;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Insights\InsightsScenario;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PublicGraphResultsTest extends KernelTestCase
{
    use InsightsScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_a_broken_graph_gives_no_result_and_computes_nothing(): void
    {
        $project = $this->scenarioProject('public-broken');
        $graph = $this->seedSavedGraph($project, 'Tests', 0);

        self::assertNull($this->publicResults()->forGraph($graph));
        self::assertNull($this->dashboardResults()->get($graph));
    }

    public function test_a_graph_split_by_account_shows_numbers_and_the_kept_entry_keeps_the_names(): void
    {
        $project = $this->scenarioProject('public-account');
        $em = $this->em();
        $run = $this->seedRun($em, $project, cardNumber: 1, endedAt: new \DateTimeImmutable('-5 days'));
        $this->seedUsage($em, $run, costUsd: '3.00');
        $em->getConnection()->executeStatement('UPDATE bridge_worker_run_facts SET account = :account WHERE run_id = :run', ['account' => 'secret-account', 'run' => (string) $run->id]);
        $graph = $this->costGraph($project);

        $result = $this->publicResults()->forGraph($graph);

        self::assertNotNull($result);
        self::assertSame(['Account 1'], $result->labels);
        self::assertSame(['secret-account'], $this->keptLabels($graph));
    }

    public function test_a_graph_with_no_split_keeps_its_labels(): void
    {
        $project = $this->scenarioProject('public-plain');
        $em = $this->em();
        $this->seedUsage($em, $this->seedRun($em, $project, cardNumber: 1, endedAt: new \DateTimeImmutable('-5 days')), costUsd: '3.00');
        $graph = $this->costGraph($project);
        $graph->group = MetricGroup::None;

        $result = $this->publicResults()->forGraph($graph);

        self::assertNotNull($result);
        self::assertSame($this->keptLabels($graph), $result->labels);
    }

    public function test_a_page_that_computes_keeps_the_result_for_the_next_visitor(): void
    {
        $project = $this->scenarioProject('public-keeps');
        $em = $this->em();
        $this->seedUsage($em, $this->seedRun($em, $project, cardNumber: 1, endedAt: new \DateTimeImmutable('-5 days')), costUsd: '3.00');
        $graph = $this->costGraph($project);
        self::assertNull($this->dashboardResults()->get($graph));

        $this->publicResults()->forGraph($graph);

        self::assertNotNull($this->dashboardResults()->get($graph));
    }

    public function test_a_result_younger_than_the_sweep_interval_is_used_as_it_is(): void
    {
        $project = $this->scenarioProject('public-fresh');
        $graph = $this->costGraph($project);
        $this->publicResults()->forGraph($graph);
        $firstAt = $this->dashboardResults()->get($graph)?->computedAt;

        $this->publicResults()->forGraph($graph);

        self::assertEquals($firstAt, $this->dashboardResults()->get($graph)?->computedAt);
    }

    public function test_a_result_older_than_the_sweep_interval_is_computed_again(): void
    {
        $project = $this->scenarioProject('public-old');
        $graph = $this->costGraph($project);
        $keeper = self::getContainer()->get(InsightsResultKeeper::class);
        self::assertInstanceOf(InsightsResultKeeper::class, $keeper);
        $keeper->graph($graph);
        $old = $this->dashboardResults()->get($graph)?->computedAt;
        usleep(1_100_000);
        $clock = new MockClock(new \DateTimeImmutable()->modify('+20 minutes'));

        $this->publicResultsWithClock($clock)->forGraph($graph);

        self::assertGreaterThan($old, $this->dashboardResults()->get($graph)?->computedAt);
    }

    public function test_a_broken_graph_has_no_kept_result(): void
    {
        $graph = $this->seedSavedGraph($this->scenarioProject('public-kept-broken'), 'Tests', 0);

        self::assertNull($this->publicResults()->kept($graph));
        self::assertNull($this->dashboardResults()->get($graph));
    }

    public function test_the_view_of_a_kept_result_is_what_the_share_page_shows(): void
    {
        $project = $this->scenarioProject('public-view');
        $em = $this->em();
        $this->seedUsage($em, $this->seedRun($em, $project, cardNumber: 1, endedAt: new \DateTimeImmutable('-5 days')), costUsd: '3.00');
        $graph = $this->costGraph($project);
        $results = $this->publicResults();

        $kept = $results->kept($graph);

        self::assertNotNull($kept);
        self::assertEquals($results->forGraph($graph), $results->view($project, $kept));
    }

    public function test_the_view_draws_into_the_box_it_is_given(): void
    {
        $project = $this->scenarioProject('public-box');
        $em = $this->em();
        $this->seedUsage($em, $this->seedRun($em, $project, cardNumber: 1, endedAt: new \DateTimeImmutable('-5 days')), costUsd: '3.00');
        $graph = $this->costGraph($project);
        $results = $this->publicResults();
        $kept = $results->kept($graph);
        self::assertNotNull($kept);

        $default = $results->view($project, $kept)->layout;
        $preview = $results->view($project, $kept, ChartBox::preview())->layout;

        self::assertInstanceOf(LineLayout::class, $default);
        self::assertInstanceOf(LineLayout::class, $preview);
        self::assertNotEquals($default->yTicks[0]->position, $preview->yTicks[0]->position);
        self::assertSame((float) ChartBox::preview()->baseline, $preview->yTicks[0]->position);
    }

    private function costGraph(Project $project): InsightsSavedGraph
    {
        $graph = $this->seedSavedGraph($project, 'Cost', 0);
        $graph->metric = 'cost';
        $graph->unit = MetricUnit::Run;
        $graph->statistic = MetricStatistic::Sum;
        $graph->group = MetricGroup::Account;
        $graph->range = MetricRange::ThirtyDays;
        $graph->compare = MetricCompare::Previous;
        $this->em()->flush();

        return $graph;
    }

    private function publicResults(): PublicGraphResults
    {
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);

        return $this->publicResultsWithClock($clock);
    }

    private function publicResultsWithClock(ClockInterface $clock): PublicGraphResults
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        $labels = self::getContainer()->get(PublicSeriesLabelRepository::class);
        $health = self::getContainer()->get(SavedGraphHealth::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        self::assertInstanceOf(PublicSeriesLabelRepository::class, $labels);
        self::assertInstanceOf(SavedGraphHealth::class, $health);

        $keeper = self::getContainer()->get(InsightsResultKeeper::class);
        $settings = self::getContainer()->get(InsightsRefreshSettings::class);
        self::assertInstanceOf(InsightsResultKeeper::class, $keeper);
        self::assertInstanceOf(InsightsRefreshSettings::class, $settings);

        return new PublicGraphResults($health, $this->dashboardResults(), $keeper, $settings, new PublicSeriesLabels($this->em(), $labels, $translator), $this->keptView(), $clock);
    }

    /** @return list<string>|null */
    private function keptLabels(InsightsSavedGraph $graph): ?array
    {
        $kept = $this->dashboardResults()->get($graph);

        return null === $kept ? null : $this->keptView()->build($kept, 'en')->labels;
    }

    private function keptView(): KeptGraphView
    {
        $view = self::getContainer()->get(KeptGraphView::class);
        self::assertInstanceOf(KeptGraphView::class, $view);

        return $view;
    }

    private function dashboardResults(): DashboardGraphResults
    {
        $results = self::getContainer()->get(DashboardGraphResults::class);
        self::assertInstanceOf(DashboardGraphResults::class, $results);

        return $results;
    }
}
