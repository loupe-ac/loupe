<?php

declare(strict_types=1);

namespace App\Module\Project\EventListener;

use App\Module\Project\Service\ProjectsChangedPublisher;
use App\Module\Review\Event\DocumentStatusChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** The row of a project shows its document count, which an archive changes. */
#[AsEventListener]
final readonly class PublishProjectsChangedOnDocumentStatusChanged
{
    public function __construct(
        private ProjectsChangedPublisher $publisher,
    ) {
    }

    public function __invoke(DocumentStatusChanged $event): void
    {
        $this->publisher->projectChanged($event->projectId);
    }
}
