<?php

declare(strict_types=1);

namespace App\Module\Project\EventListener;

use App\Module\Project\Service\ProjectsChangedPublisher;
use App\Module\SiteReview\Event\SiteReviewCommentStatusChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Listens to the event itself, because the card event follows it only for a
 * note that a card links. A note with no card still moves the count.
 */
#[AsEventListener]
final readonly class PublishProjectsChangedOnSiteReviewCommentStatusChanged
{
    public function __construct(
        private ProjectsChangedPublisher $publisher,
    ) {
    }

    public function __invoke(SiteReviewCommentStatusChanged $event): void
    {
        $this->publisher->projectChanged($event->projectId);
    }
}
