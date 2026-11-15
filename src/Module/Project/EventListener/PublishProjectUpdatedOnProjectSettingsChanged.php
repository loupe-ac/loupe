<?php

declare(strict_types=1);

namespace App\Module\Project\EventListener;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Project\Event\ProjectSettingsChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Tells the open edit forms of a project that another actor saved a setting.
 * The message carries no value, because each form reads its own state.
 */
final readonly class PublishProjectUpdatedOnProjectSettingsChanged
{
    public const string TYPE = 'project.updated';

    public function __construct(
        private ProjectTopicBuilder $topics,
        private LiveUpdatePublisher $publisher,
    ) {
    }

    #[AsEventListener]
    public function __invoke(ProjectSettingsChanged $event): void
    {
        $this->publisher->queue($this->topics->forBoard($event->projectId), [
            'type' => self::TYPE,
            'projectId' => (string) $event->projectId,
        ]);
    }
}
