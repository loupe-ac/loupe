<?php

declare(strict_types=1);

namespace App\Module\Insights\Messenger;

use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Insights\Repository\PublicLinkRepository;
use App\Module\Insights\Repository\PublicLinkTakedownRepository;
use App\Module\Insights\Service\DashboardChangedPublisher;
use App\Module\Insights\Service\InsightsDataKind;
use App\Module\Insights\Service\InsightsDataKinds;
use App\Module\Insights\Service\InsightsRefreshHolds;
use App\Module\Insights\Service\InsightsRefreshRequester;
use App\Module\Insights\Service\InsightsResultKeeper;
use App\Module\Insights\Service\SavedGraphHealth;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Refreshes the graphs and the shared experiments of a project that read one kind of data. It catches the failure of
 * each item and queues that item again, so one broken graph never stops the others and Messenger retries it alone.
 * One signal names every graph that got a newer result.
 */
#[AsMessageHandler]
final readonly class RefreshProjectInsightsHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private InsightsSavedGraphRepository $insightsSavedGraphs,
        private PublicLinkRepository $publicLinks,
        private PublicLinkTakedownRepository $publicLinkTakedowns,
        private InsightsDataKinds $dataKinds,
        private InsightsResultKeeper $keeper,
        private InsightsRefreshHolds $holds,
        private InsightsRefreshRequester $requester,
        private SavedGraphHealth $health,
        private DashboardChangedPublisher $dashboardChanges,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RefreshProjectInsights $message): void
    {
        $kind = InsightsDataKind::tryFrom($message->kind);
        $project = $this->projects->find($message->projectId);
        if (null === $kind || null === $project) {
            return;
        }

        $keptGraphIds = InsightsDataKind::Experiment === $kind ? [] : $this->refreshGraphs($project, $kind);
        foreach ($this->experimentsOf($project, $kind, $message->experiment) as $experiment) {
            try {
                $this->keeper->experiment($project, $experiment, true);
            } catch (\Throwable $e) {
                $this->logger->warning('insights.item_refresh_failed', ['projectId' => $message->projectId, 'experiment' => $experiment, 'error' => $e->getMessage()]);
                $this->requester->experiment($project, $experiment);
            }
        }

        $this->dashboardChanges->graphsRefreshed($project->requireId(), $keptGraphIds);
    }

    /** @return list<string> the ids of the graphs that got a newer result */
    private function refreshGraphs(Project $project, InsightsDataKind $kind): array
    {
        $kept = [];
        foreach ($this->insightsSavedGraphs->findActive($project) as $graph) {
            $graphId = (string) $graph->id;
            if (!\in_array($kind, $this->dataKinds->reads($graph), true) || $this->holds->holds($graphId) || [] !== $this->health->check($project, [$graph])) {
                continue;
            }
            try {
                if ($this->keeper->graphById((string) $project->id, $graphId)) {
                    $kept[] = $graphId;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('insights.item_refresh_failed', ['projectId' => (string) $project->id, 'graphId' => $graphId, 'error' => $e->getMessage()]);
                $this->requester->graph($graph);
            }
        }

        return $kept;
    }

    /** @return list<string> the shared experiments that read the kind and have a switched-on link */
    private function experimentsOf(Project $project, InsightsDataKind $kind, ?string $experiment): array
    {
        if ($this->publicLinkTakedowns->existsForProject($project)) {
            return [];
        }
        $links = $this->publicLinks->findExperimentLinks($project);
        $names = InsightsDataKind::Experiment === $kind
            ? (null === $experiment ? [] : [$experiment])
            : ($this->dataKinds->experimentReads($kind) ? array_keys($links) : []);

        return array_values(array_filter(
            array_map(strval(...), $names),
            static fn (string $name): bool => isset($links[$name]) && $links[$name]->enabled,
        ));
    }
}
