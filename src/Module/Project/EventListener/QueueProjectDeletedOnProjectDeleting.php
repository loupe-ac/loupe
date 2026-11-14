<?php

declare(strict_types=1);

namespace App\Module\Project\EventListener;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Project\Event\ProjectDeleting;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Tells the open pages of a project that it is gone. The signal goes on the
 * worker runs topic, which every project page with the sidebar listens on. The
 * publisher holds it until the deletion commits.
 */
#[AsEventListener]
final readonly class QueueProjectDeletedOnProjectDeleting
{
    public function __construct(
        private LiveUpdatePublisher $live,
        private ProjectTopicBuilder $topics,
    ) {
    }

    public function __invoke(ProjectDeleting $event): void
    {
        $projectId = $event->project->id ?? throw new \LogicException('Project has no id.');

        $this->live->queue($this->topics->forWorkerRuns($projectId), [
            'type' => 'project.deleted',
            'projectId' => (string) $projectId,
        ]);
    }
}
