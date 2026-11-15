<?php

declare(strict_types=1);

namespace App\Module\Project\EventListener;

use App\Module\Artifact\Event\ArtifactChanged;
use App\Module\Project\Service\ProjectsChangedPublisher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** The row of a project shows its artifact count, which a stored version, an archive or a restore changes. */
#[AsEventListener]
final readonly class PublishProjectsChangedOnArtifactChanged
{
    public function __construct(
        private ProjectsChangedPublisher $publisher,
    ) {
    }

    public function __invoke(ArtifactChanged $event): void
    {
        $this->publisher->projectChanged($event->projectId);
    }
}
