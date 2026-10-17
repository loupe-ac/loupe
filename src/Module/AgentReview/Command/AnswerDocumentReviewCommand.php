<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Command;

use App\Module\AgentReview\Entity\DocumentReviewFindingAnswer;
use App\Module\Board\Entity\Card;
use App\Module\Review\Entity\Document;
use Symfony\Component\Uid\Uuid;

final readonly class AnswerDocumentReviewCommand
{
    /**
     * @param ?Uuid                             $sessionId the session of the fix worker, or null when the call names none
     * @param list<DocumentReviewFindingAnswer> $answers
     */
    public function __construct(
        public Card $card,
        public Document $document,
        public ?Uuid $sessionId,
        public array $answers,
    ) {
    }
}
