<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Board\Event\BoardAutomationSettingsSaved;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Tells every open automation settings form of a project that its settings
 * changed. The message carries no settings, because the form reads them again.
 */
final readonly class PublishAutomationChangedOnBoardAutomationSettingsSaved
{
    public const string TYPE = 'board.automation_changed';

    public function __construct(
        private ProjectTopicBuilder $topics,
        private LiveUpdatePublisher $publisher,
    ) {
    }

    #[AsEventListener]
    public function __invoke(BoardAutomationSettingsSaved $event): void
    {
        $projectId = $event->project->id ?? throw new \LogicException('Project has no id.');
        $this->publisher->queue($this->topics->forBoard($projectId), ['type' => self::TYPE]);
    }
}
