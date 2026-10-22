<?php

declare(strict_types=1);

namespace App\Module\AgentReview\EventListener;

use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Review\Service\DraftStateSignal;
use App\Module\Workflow\Contract\DesignReviewTags;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

/**
 * A new round, a pause start or end, and a card move change what the band of
 * each draft design of the card shows.
 */
#[AsEventListener]
final readonly class SignalDraftStateOnCardChanged
{
    public function __construct(
        private DesignReviewTags $designReviewTags,
        private CardDocumentRepository $cardDocuments,
        private DraftStateSignal $signal,
    ) {
    }

    public function __invoke(CardChanged $event): void
    {
        if (CardChanged::DELETED === $event->change) {
            return;
        }

        $tags = $this->designReviewTags->of($event->projectId);
        if ([] === $tags) {
            return;
        }

        foreach ($this->cardDocuments->findDraftDocumentIdsWithTagsForCard($event->cardId, $tags) as $documentId) {
            $this->signal->send($event->projectId, Uuid::fromString($documentId));
        }
    }
}
