<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Command;

use App\Module\AgentReview\Entity\DocumentReviewFindingAnswer;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\ValueObject\WorkerRunKey;
use App\Module\Review\Entity\Document;

final readonly class AnswerDocumentReviewCommand
{
    /**
     * @param ?WorkerRunKey                     $runKey  the launch of the fix worker, or null when the call holds no run credential
     * @param list<DocumentReviewFindingAnswer> $answers
     */
    public function __construct(
        public Card $card,
        public Document $document,
        public ?WorkerRunKey $runKey,
        public array $answers,
    ) {
    }
}
