<?php

declare(strict_types=1);

namespace App\Module\Project\Service;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\UserTopicBuilder;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Tells the open projects list of an owner that a project, or a count a row of
 * the list shows, changed. The message names the area and no value, because
 * each page reads its own state. The publisher holds it until the change commits.
 */
final readonly class ProjectsChangedPublisher
{
    public const string TYPE = 'account.changed';

    public const string AREA = 'projects';

    public function __construct(
        private UserTopicBuilder $topics,
        private ProjectRepository $projects,
        private LiveUpdatePublisher $publisher,
    ) {
    }

    public function ownerChanged(Uuid $ownerId): void
    {
        $this->publisher->queue($this->topics->forAccount($ownerId), [
            'type' => self::TYPE,
            'area' => self::AREA,
        ]);
    }

    /** A project that no longer exists has no owner to tell, so the call does nothing. */
    public function projectChanged(Uuid $projectId): void
    {
        $ownerId = $this->projects->find($projectId)?->owner->id;
        if (null !== $ownerId) {
            $this->ownerChanged($ownerId);
        }
    }
}
