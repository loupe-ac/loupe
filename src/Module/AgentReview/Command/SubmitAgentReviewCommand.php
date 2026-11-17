<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Command;

use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\ValueObject\WorkerRunKey;

final readonly class SubmitAgentReviewCommand
{
    /**
     * @param ?WorkerRunKey            $runKey   the launch of the review worker, or null when the call holds no run credential
     * @param list<AgentReviewFinding> $findings
     */
    public function __construct(
        public Card $card,
        public ?WorkerRunKey $runKey,
        public string $pullRequestUrl,
        public string $headSha,
        public string $summary,
        public array $findings,
    ) {
    }
}
