<?php

declare(strict_types=1);

namespace App\Module\Project\EventListener;

use App\Module\Project\Event\ProjectDeleting;
use App\Module\Project\Service\ProjectsChangedPublisher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A deleted project leaves the projects list of its owner. The publisher holds the signal until the delete commits. */
#[AsEventListener]
final readonly class PublishProjectsChangedOnProjectDeleting
{
    public function __construct(
        private ProjectsChangedPublisher $publisher,
    ) {
    }

    public function __invoke(ProjectDeleting $event): void
    {
        $this->publisher->ownerChanged($event->project->owner->id ?? throw new \LogicException('Project owner has no id.'));
    }
}
