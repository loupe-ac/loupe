<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\ProjectTopicBuilder;
use App\Mercure\UserTopicBuilder;
use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

/**
 * Tells the open pages that the inbox of a project changed. The owner's pages
 * reload the sidebar pill, and the inbox pages of the project reload their
 * view. The messages hold no count and no text: two publishes can arrive out
 * of order, and a page that reads the state itself cannot go back in time.
 */
final readonly class InboxChangedPublisher
{
    public const string TYPE = 'inbox.open_count_changed';

    public const string PAGE_TYPE = 'inbox.changed';

    public function __construct(
        private UserTopicBuilder $userTopics,
        private ProjectTopicBuilder $projectTopics,
        private LiveUpdatePublisher $publisher,
    ) {
    }

    /**
     * An item of the project opened or closed. The topics and payloads are built
     * now: after a rollback the EntityManager is closed and cannot load the owner.
     *
     * @param list<Uuid> $askIds the asks that changed, when the caller holds them
     */
    public function changed(Project $project, array $askIds = []): void
    {
        $projectId = $project->id ?? throw new \LogicException('Project has no id.');
        $ownerId = $project->owner->id ?? throw new \LogicException('Project owner has no id.');
        $this->publisher->queue($this->userTopics->forInbox($ownerId), ['type' => self::TYPE, 'projectId' => (string) $projectId]);
        $this->pageChanged($project, $askIds);
    }

    /**
     * The view changed and no item opened or closed, so only the inbox pages
     * read it again.
     *
     * @param list<Uuid> $askIds the asks that changed, when the caller holds them
     */
    public function pageChanged(Project $project, array $askIds = []): void
    {
        $projectId = $project->id ?? throw new \LogicException('Project has no id.');
        $this->publisher->queue($this->projectTopics->forInbox($projectId), [
            'type' => self::PAGE_TYPE,
            'askIds' => array_map(static fn (Uuid $askId): string => (string) $askId, $askIds),
        ]);
    }
}
