<?php

declare(strict_types=1);

namespace App\Module\Project\EventListener;

use App\Module\Project\Entity\Project;
use App\Module\Project\Service\ProjectsChangedPublisher;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * A project insert or update shows on the projects list as a new row or a new
 * name. Doctrine calls these hooks inside the transaction of the flush, so a
 * failed flush drops the signal. Every handler that persists a project, and the
 * rename, pass here, whatever event they dispatch before the flush.
 */
#[AsEntityListener(event: Events::postPersist, method: 'saved', entity: Project::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'saved', entity: Project::class)]
final readonly class PublishProjectsChangedOnProjectSaved
{
    public function __construct(
        private ProjectsChangedPublisher $publisher,
    ) {
    }

    public function saved(Project $project): void
    {
        $this->publisher->ownerChanged($project->owner->id ?? throw new \LogicException('Project owner has no id.'));
    }
}
