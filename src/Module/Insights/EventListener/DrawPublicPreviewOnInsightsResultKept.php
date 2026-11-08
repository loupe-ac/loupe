<?php

declare(strict_types=1);

namespace App\Module\Insights\EventListener;

use App\Module\Insights\Event\InsightsResultKept;
use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Insights\Repository\PublicLinkRepository;
use App\Module\Insights\Service\PublicLinkVisibility;
use App\Module\Insights\Service\PublicPreviewRequester;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A new kept result of a graph or an experiment with a visible link needs a new preview image. */
#[AsEventListener]
final readonly class DrawPublicPreviewOnInsightsResultKept
{
    public function __construct(
        private InsightsSavedGraphRepository $insightsSavedGraphs,
        private ProjectRepository $projects,
        private PublicLinkRepository $publicLinks,
        private PublicLinkVisibility $visibility,
        private PublicPreviewRequester $requester,
    ) {
    }

    public function __invoke(InsightsResultKept $event): void
    {
        if (null !== $event->graphId) {
            $graph = $this->insightsSavedGraphs->findOneByIdAndProjectId($event->graphId, $event->projectId);
            $link = null === $graph ? null : $this->publicLinks->findForGraph($graph);
        } elseif (null !== $event->experiment) {
            $project = $this->projects->find($event->projectId);
            $link = null === $project ? null : $this->publicLinks->findForExperiment($project, $event->experiment);
        } else {
            return;
        }
        if (null === $link || null === $this->visibility->resolve($link->code)) {
            return;
        }

        $this->requester->draw($link->code);
    }
}
