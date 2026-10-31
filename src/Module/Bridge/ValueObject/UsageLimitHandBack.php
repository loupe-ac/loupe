<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

use Symfony\Component\Uid\Uuid;

/** What a bridge says when a usage limit stopped its run and it gives the work request back. */
final readonly class UsageLimitHandBack
{
    /**
     * @param float|null $usedPercent      the use of the window from 0 to 200, null when the source gives none
     * @param Uuid|null  $sessionId        the session the next run resumes
     * @param bool       $hasAssistantTurn whether the run did any work, so its session and changes are worth resuming
     */
    public function __construct(
        public string $account,
        public string $window,
        public ?float $usedPercent,
        public \DateTimeImmutable $resetsAt,
        public bool $limited,
        public string $source,
        public ?Uuid $sessionId,
        public bool $hasAssistantTurn,
    ) {
    }

    /** The reading that showed the limit, as the bridge would send it in a heartbeat. */
    public function report(\DateTimeImmutable $readAt): AccountReadingReport
    {
        return new AccountReadingReport($this->account, $this->window, $this->usedPercent, $this->resetsAt, $this->limited, $this->source, $readAt);
    }
}
