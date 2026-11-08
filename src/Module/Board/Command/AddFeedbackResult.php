<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\CardSiteReviewComment;

final readonly class AddFeedbackResult
{
    public function __construct(
        public CardSiteReviewComment $link,
        /** The label of the start column the note asked for and the workflow refused, or null. */
        public ?string $startRefused,
    ) {
    }
}
