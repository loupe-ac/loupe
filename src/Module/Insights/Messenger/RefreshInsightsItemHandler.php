<?php

declare(strict_types=1);

namespace App\Module\Insights\Messenger;

use App\Module\Insights\Repository\PublicLinkRepository;
use App\Module\Insights\Repository\PublicLinkTakedownRepository;
use App\Module\Insights\Service\DashboardChangedPublisher;
use App\Module\Insights\Service\InsightsRefreshHolds;
use App\Module\Insights\Service\InsightsResultKeeper;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Computes and keeps one item. A removed graph, a broken graph and an experiment with no switched-on link keep nothing.
 * A compute that fails throws, so Messenger retries the item.
 */
#[AsMessageHandler]
final readonly class RefreshInsightsItemHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private PublicLinkRepository $publicLinks,
        private PublicLinkTakedownRepository $publicLinkTakedowns,
        private InsightsResultKeeper $keeper,
        private InsightsRefreshHolds $holds,
        private DashboardChangedPublisher $dashboardChanges,
    ) {
    }

    public function __invoke(RefreshInsightsItem $message): void
    {
        if (null !== $message->graphId) {
            if ($this->holds->holds($message->graphId)) {
                return;
            }
            if ($this->keeper->graphById($message->projectId, $message->graphId) && Uuid::isValid($message->projectId)) {
                $this->dashboardChanges->graphsRefreshed(Uuid::fromString($message->projectId), [$message->graphId]);
            }

            return;
        }

        $project = $this->projects->find($message->projectId);
        if (null === $project || null === $message->experiment) {
            return;
        }
        $link = $this->publicLinks->findForExperiment($project, $message->experiment);
        if (null === $link || !$link->enabled || $this->publicLinkTakedowns->existsForProject($project)) {
            return;
        }
        $this->keeper->experiment($project, $message->experiment, true);
    }
}
