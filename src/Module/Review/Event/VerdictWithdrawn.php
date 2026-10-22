<?php

declare(strict_types=1);

namespace App\Module\Review\Event;

use App\Module\Review\Entity\Review;

/** Dispatched inside the withdrawal transaction, with the document locked. */
final readonly class VerdictWithdrawn
{
    public function __construct(
        public Review $withdrawal,
        public Review $withdrawn,
    ) {
    }
}
