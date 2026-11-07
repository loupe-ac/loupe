<?php

declare(strict_types=1);

namespace App\Module\Insights\Messenger;

use App\Module\Insights\Repository\PublicLinkRepository;
use App\Module\Insights\Repository\PublicLinkTakedownRepository;
use App\Module\Insights\Service\InsightsRefreshHold;
use App\Module\Insights\Service\InsightsResultKeeper;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

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

        /** @var iterable<InsightsRefreshHold> */
        #[AutowireIterator('app.insights_refresh_hold')]
        private iterable $holds,
    ) {
    }

    public function __invoke(RefreshInsightsItem $message): void
    {
        if (null !== $message->graphId) {
            foreach ($this->holds as $hold) {
                if ($hold->holds($message->graphId)) {
                    return;
                }
            }
            $this->keeper->graphById($message->projectId, $message->graphId);

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
