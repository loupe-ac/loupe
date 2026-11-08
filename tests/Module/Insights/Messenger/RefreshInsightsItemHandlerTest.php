<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Messenger;

use App\Module\Bridge\Metric\MetricCompare;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Entity\PublicLinkTakedown;
use App\Module\Insights\Messenger\RefreshInsightsItem;
use App\Module\Insights\Messenger\RefreshInsightsItemHandler;
use App\Module\Insights\Repository\InsightsKeptResultRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RefreshInsightsItemHandlerTest extends KernelTestCase
{
    use InsightsScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_a_graph_message_computes_and_keeps_the_graph(): void
    {
        $project = $this->scenarioProject('refresh-graph');
        $graph = $this->seedSavedGraph($project, 'Cost', 0);
        $graph->metric = 'cost';
        $graph->unit = MetricUnit::Run;
        $graph->statistic = MetricStatistic::Median;
        $graph->range = MetricRange::ThirtyDays;
        $graph->compare = MetricCompare::Previous;
        $this->em()->flush();
        $graphId = (string) $graph->id;
        $this->em()->clear();

        $this->handler()(new RefreshInsightsItem((string) $project->id, $graphId, null));

        self::assertSame(1, $this->keptRows());
    }

    public function test_an_experiment_with_no_link_keeps_nothing(): void
    {
        $project = $this->scenarioProject('refresh-nolink');
        $this->seedExperiment($project, 'trial');

        $this->handler()(new RefreshInsightsItem((string) $project->id, null, 'trial'));

        self::assertSame(0, $this->keptRows());
    }

    public function test_an_experiment_with_a_switched_off_link_keeps_nothing(): void
    {
        $project = $this->scenarioProject('refresh-off');
        $this->seedExperiment($project, 'trial');
        $link = PublicLink::forExperiment($project, 'trial');
        $link->enabled = false;
        $this->em()->persist($link);
        $this->em()->flush();

        $this->handler()(new RefreshInsightsItem((string) $project->id, null, 'trial'));

        self::assertSame(0, $this->keptRows());
    }

    public function test_an_experiment_with_a_switched_on_link_is_kept(): void
    {
        $project = $this->sharedExperiment('refresh-on');

        $this->handler()(new RefreshInsightsItem((string) $project->id, null, 'trial'));

        self::assertNotNull($this->kept()->findForExperiment($project, 'trial'));
    }

    public function test_a_project_that_an_admin_took_down_keeps_nothing(): void
    {
        $project = $this->sharedExperiment('refresh-takedown');
        $this->em()->persist(new PublicLinkTakedown($project, null));
        $this->em()->flush();

        $this->handler()(new RefreshInsightsItem((string) $project->id, null, 'trial'));

        self::assertSame(0, $this->keptRows());
    }

    private function sharedExperiment(string $name): Project
    {
        $project = $this->scenarioProject($name);
        $this->seedExperiment($project, 'trial');
        $this->em()->persist(PublicLink::forExperiment($project, 'trial'));
        $this->em()->flush();

        return $project;
    }

    private function keptRows(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM insights_kept_results');
    }

    private function kept(): InsightsKeptResultRepository
    {
        $kept = self::getContainer()->get(InsightsKeptResultRepository::class);
        self::assertInstanceOf(InsightsKeptResultRepository::class, $kept);

        return $kept;
    }

    private function handler(): RefreshInsightsItemHandler
    {
        $handler = self::getContainer()->get(RefreshInsightsItemHandler::class);
        self::assertInstanceOf(RefreshInsightsItemHandler::class, $handler);

        return $handler;
    }
}
