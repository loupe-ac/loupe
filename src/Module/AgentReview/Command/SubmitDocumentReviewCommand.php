<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Command;

use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\Board\Entity\Card;
use App\Module\Review\Entity\Document;
use Symfony\Component\Uid\Uuid;

final readonly class SubmitDocumentReviewCommand
{
    /**
     * @param ?Uuid                    $sessionId the session of the review worker, or null when the call names none
     * @param list<AgentReviewFinding> $findings
     */
    public function __construct(
        public Card $card,
        public Document $document,
        public ?Uuid $sessionId,
        public int $versionNumber,
        public string $summary,
        public array $findings,
    ) {
    }
}
