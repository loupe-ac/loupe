<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** One reading of one usage window of an account, as the bridge reports it. */
final readonly class AccountReadingReport
{
    /**
     * @param float|null $usedPercent the use of the window from 0 to 200, null when the source gives none
     */
    public function __construct(
        public string $account,
        public string $window,
        public ?float $usedPercent,
        public \DateTimeImmutable $resetsAt,
        public bool $limited,
        public string $source,
        public \DateTimeImmutable $readAt,
    ) {
    }

    /** Whether the other reading says the same about the window. The source and the read time do not count. */
    public function says(self $other): bool
    {
        return $this->usedPercent === $other->usedPercent
            && $this->resetsAt->getTimestamp() === $other->resetsAt->getTimestamp()
            && $this->limited === $other->limited;
    }
}
