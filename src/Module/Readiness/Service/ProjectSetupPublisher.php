<?php

declare(strict_types=1);

namespace App\Module\Readiness\Service;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\ProjectTopicBuilder;
use Symfony\Component\Uid\Uuid;

/** Tells the open setup pages of a project to reload their list. The message names no state. */
final readonly class ProjectSetupPublisher
{
    public const string TYPE = 'project.setup_changed';

    public function __construct(
        private ProjectTopicBuilder $topics,
        private LiveUpdatePublisher $publisher,
    ) {
    }

    /** Call it after the change commits. */
    public function changed(Uuid $projectId): void
    {
        $this->publisher->queue($this->topics->forBoard($projectId), ['type' => self::TYPE, 'projectId' => $projectId->toRfc4122()]);
    }
}
