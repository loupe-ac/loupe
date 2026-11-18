<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Command;

use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\ValueObject\WorkerRunKey;
use App\Module\Review\Entity\Document;

final readonly class SubmitDocumentReviewCommand
{
    /**
     * @param ?WorkerRunKey            $runKey   the launch of the review worker, or null when the call holds no run credential
     * @param list<AgentReviewFinding> $findings
     */
    public function __construct(
        public Card $card,
        public Document $document,
        public ?WorkerRunKey $runKey,
        public int $versionNumber,
        public string $summary,
        public array $findings,
    ) {
    }
}
