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
use App\Module\Insights\Service\PublicGraphResults;
use App\Module\Insights\Service\PublicSeriesLabels;
use App\Module\Insights\Service\SavedGraphHealth;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
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
        self::assertNull($this->dashboardResults()->get($project, $graph));
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
        self::assertSame(['secret-account'], $this->dashboardResults()->get($project, $graph)?->labels);
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
        self::assertSame($this->dashboardResults()->get($project, $graph)?->labels, $result->labels);
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
        $translator = self::getContainer()->get(TranslatorInterface::class);
        $labels = self::getContainer()->get(PublicSeriesLabelRepository::class);
        $health = self::getContainer()->get(SavedGraphHealth::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        self::assertInstanceOf(PublicSeriesLabelRepository::class, $labels);
        self::assertInstanceOf(SavedGraphHealth::class, $health);

        return new PublicGraphResults($health, $this->dashboardResults(), new PublicSeriesLabels($this->em(), $labels, $translator));
    }

    private function dashboardResults(): DashboardGraphResults
    {
        $results = self::getContainer()->get(DashboardGraphResults::class);
        self::assertInstanceOf(DashboardGraphResults::class, $results);

        return $results;
    }
}
