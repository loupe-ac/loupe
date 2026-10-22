<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

final readonly class DraftReviewLoop
{
    public function __construct(
        public int $round,
        public int $limit,
        /** The translated text of the pause reason, or null when the card is not paused. */
        public ?string $pauseReason,
        public string $cardId,
        public int $cardNumber,
    ) {
    }

    public function paused(): bool
    {
        return null !== $this->pauseReason;
    }
}
