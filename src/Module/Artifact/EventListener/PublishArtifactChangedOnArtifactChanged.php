<?php

declare(strict_types=1);

namespace App\Module\Artifact\EventListener;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Artifact\Event\ArtifactChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Tells the open artifact pages of the project what changed, naming ids and a version number only. */
#[AsEventListener]
final readonly class PublishArtifactChangedOnArtifactChanged
{
    public const string TYPE = 'artifact.changed';

    public function __construct(
        private ProjectTopicBuilder $topics,
        private LiveUpdatePublisher $publisher,
    ) {
    }

    public function __invoke(ArtifactChanged $event): void
    {
        $this->publisher->queue($this->topics->forArtifacts($event->projectId), [
            'type' => self::TYPE,
            'artifactId' => (string) $event->artifactId,
            'kind' => $event->change->value,
            'versionNumber' => $event->versionNumber,
        ]);
    }
}
