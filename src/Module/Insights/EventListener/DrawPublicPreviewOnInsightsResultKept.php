<?php

declare(strict_types=1);

namespace App\Module\Insights\EventListener;

use App\Module\Insights\Event\InsightsResultKept;
use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Insights\Repository\PublicLinkRepository;
use App\Module\Insights\Service\PublicLinkVisibility;
use App\Module\Insights\Service\PublicPreviewRequester;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A new kept result of a graph with a visible link needs a new preview image. */
#[AsEventListener]
final readonly class DrawPublicPreviewOnInsightsResultKept
{
    public function __construct(
        private InsightsSavedGraphRepository $insightsSavedGraphs,
        private PublicLinkRepository $publicLinks,
        private PublicLinkVisibility $visibility,
        private PublicPreviewRequester $requester,
    ) {
    }

    public function __invoke(InsightsResultKept $event): void
    {
        if (null === $event->graphId) {
            return;
        }
        $graph = $this->insightsSavedGraphs->findOneByIdAndProjectId($event->graphId, $event->projectId);
        $link = null === $graph ? null : $this->publicLinks->findForGraph($graph);
        if (null === $link || null === $this->visibility->resolve($link->code)) {
            return;
        }

        $this->requester->draw($link->code);
    }
}
