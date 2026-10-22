<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Service\DraftReviewLoop;

final readonly class ShowDraftStateResult
{
    /** @param ?DraftReviewLoop $loop null when no design review loop waits to publish the draft */
    public function __construct(
        public ?DraftReviewLoop $loop,
    ) {
    }
}
