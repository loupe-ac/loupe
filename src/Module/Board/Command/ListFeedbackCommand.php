<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;

/** One project's feedback, narrowed to a status and to one card. A null status reads every item, and a null card reads every card's. */
final readonly class ListFeedbackCommand
{
    public function __construct(
        public Project $project,
        public ?SiteReviewCommentStatus $status,
        public ?Card $card = null,
    ) {
    }
}
