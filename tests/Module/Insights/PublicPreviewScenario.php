<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights;

use App\Module\Bridge\Metric\MetricCompare;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Project\Entity\Project;

/** A shared cost graph with one run of data, for the tests of the preview image. */
trait PublicPreviewScenario
{
    use InsightsScenario;

    private function sharedCostGraph(string $name, bool $withData = true): PublicLink
    {
        $project = $this->scenarioProject($name);
        $em = $this->em();
        if ($withData) {
            $this->seedUsage($em, $this->seedRun($em, $project, cardNumber: 1, endedAt: new \DateTimeImmutable('-5 days')), costUsd: '3.00');
        }
        $graph = $this->costGraphOf($project);
        $link = PublicLink::forGraph($graph);
        $em->persist($link);
        $em->flush();

        return $link;
    }

    private function costGraphOf(Project $project): InsightsSavedGraph
    {
        $graph = $this->seedSavedGraph($project, 'Cost', 0);
        $graph->metric = 'cost';
        $graph->unit = MetricUnit::Run;
        $graph->statistic = MetricStatistic::Sum;
        $graph->group = MetricGroup::None;
        $graph->range = MetricRange::ThirtyDays;
        $graph->compare = MetricCompare::Off;
        $this->em()->flush();

        return $graph;
    }
}
