<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\ProjectTopicBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * Tells the open pages of a document that the band of a draft may have changed.
 * The message names the type alone, so the page reads the new state itself.
 * Call it after the change commits.
 */
final readonly class DraftStateSignal
{
    public const string TYPE = 'review.draft_state_changed';

    public function __construct(
        private ProjectTopicBuilder $topics,
        private LiveUpdatePublisher $publisher,
    ) {
    }

    public function send(Uuid $projectId, Uuid $documentId): void
    {
        $this->publisher->queue($this->topics->forDocument($projectId, $documentId), ['type' => self::TYPE]);
    }
}
