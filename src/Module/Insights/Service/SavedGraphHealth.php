<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Insights\Entity\InsightsBucketRule;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Repository\InsightsBucketRuleRepository;
use App\Module\Insights\View\BrokenGraph;
use App\Module\Insights\View\BrokenGraphReason;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\TemplateMetrics;

/**
 * Finds the saved graphs that name something the project no longer has. A bucket is gone when no bucket rule
 * names it, because a run keeps its bucket name after the rule goes and the data would never say so.
 */
final readonly class SavedGraphHealth
{
    public function __construct(
        private TemplateMetrics $templateMetrics,
        private InsightsBucketRuleRepository $insightsBucketRules,
        private WorkerRunRepository $workerRuns,
    ) {
    }

    /**
     * @param list<InsightsSavedGraph> $graphs
     *
     * @return array<string, BrokenGraph> the broken graphs, by graph id
     */
    public function check(Project $project, array $graphs): array
    {
        $templates = $this->templateMetrics->forProject($project->requireId());
        $buckets = null;
        $eventKinds = null;
        $broken = [];
        foreach ($graphs as $graph) {
            $key = MetricKey::tryParse($graph->metric, $templates);
            if (null === $key) {
                $broken[(string) $graph->id] = new BrokenGraph(BrokenGraphReason::TemplateMetric, $graph->metric);
                continue;
            }
            $name = $key->name;
            if (null === $name) {
                continue;
            }
            if (Metric::HarnessEvents === $key->metric) {
                $eventKinds ??= $this->workerRuns->findHarnessEventKindsOfProject($project);
                if (!\in_array($name, $eventKinds, true)) {
                    $broken[(string) $graph->id] = new BrokenGraph(BrokenGraphReason::HarnessEvent, $name);
                }
                continue;
            }
            $buckets ??= array_map(static fn (InsightsBucketRule $rule): string => $rule->bucket, $this->insightsBucketRules->findOrdered($project));
            if (!\in_array($name, $buckets, true)) {
                $broken[(string) $graph->id] = new BrokenGraph(BrokenGraphReason::Bucket, $name);
            }
        }

        return $broken;
    }
}
