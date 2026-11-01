<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

use Symfony\Component\Uid\Uuid;

/** What a bridge says when a network outage stopped its run and it gives the work request back. */
final readonly class NetworkHandBack
{
    /**
     * @param Uuid|null $sessionId        the session the next run resumes
     * @param bool      $hasAssistantTurn whether the run did any work, so its session and changes are worth resuming
     */
    public function __construct(
        public string $account,
        public ?Uuid $sessionId,
        public bool $hasAssistantTurn,
    ) {
    }
}
