<?php

declare(strict_types=1);

namespace App\Module\Project\EventListener;

use App\Module\Project\Service\ProjectsChangedPublisher;
use App\Module\SiteReview\Event\SiteReviewCommentCreated;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** The row of a project counts its site-review notes. */
#[AsEventListener]
final readonly class PublishProjectsChangedOnSiteReviewCommentCreated
{
    public function __construct(
        private ProjectsChangedPublisher $publisher,
    ) {
    }

    public function __invoke(SiteReviewCommentCreated $event): void
    {
        $this->publisher->ownerChanged($event->comment->project->owner->id ?? throw new \LogicException('Project owner has no id.'));
    }
}
