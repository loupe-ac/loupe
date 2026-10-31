<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Insights\View\MetricsQuery;
use App\Module\Workflow\Contract\TemplateMetrics;
use Symfony\Component\HttpFoundation\InputBag;

/** Finds the graph that the Metrics page edits, and the controls that draw it as it is saved. */
final readonly class ShowSavedGraphHandler
{
    public function __construct(
        private InsightsSavedGraphRepository $insightsSavedGraphs,
        private TemplateMetrics $templateMetrics,
    ) {
    }

    /** Null for a malformed id, a graph of another project and a removed graph alike. */
    public function __invoke(ShowSavedGraphCommand $command): ?SavedGraphResult
    {
        $projectId = $command->project->requireId();
        $graph = $this->insightsSavedGraphs->findOneByIdAndProjectId($command->graphId, (string) $projectId);
        if (null === $graph || null !== $graph->removedAt) {
            return null;
        }

        /** @var InputBag<string> $saved */
        $saved = new InputBag($graph->settings());

        return new SavedGraphResult($graph, MetricsQuery::fromQuery($saved, $this->templateMetrics->forProject($projectId)));
    }
}
