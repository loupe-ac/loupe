<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Service;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\ProjectTopicBuilder;
use App\Mercure\UserTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;

/**
 * Tells the open key sections that a key changed. An account key signals the
 * topic of its user, and a project key signals the topic of its project. The
 * message holds no state: a section that reads the key itself cannot go back in
 * time when two messages arrive out of order.
 */
final readonly class OpenRouterKeyChangedPublisher
{
    public const string TYPE = 'openrouter_key.changed';

    public function __construct(
        private UserTopicBuilder $userTopics,
        private ProjectTopicBuilder $projectTopics,
        private LiveUpdatePublisher $publisher,
    ) {
    }

    /** Call it after the change commits. The topic is built now, because the owner may be detached later. */
    public function changed(User|Project $owner): void
    {
        $topic = $owner instanceof User
            ? $this->userTopics->forOpenRouter($owner->id ?? throw new \LogicException('User has no id.'))
            : $this->projectTopics->forOpenRouter($owner->requireId());

        $this->publisher->queue($topic, ['type' => self::TYPE]);
    }
}
