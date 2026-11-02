<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Api\Pagination\CreatedSort;
use App\Api\Pagination\PageRequest;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use Symfony\Component\Uid\Uuid;

/** One page of a project's feedback. A null status reads every item, and a null card reads every card's. */
final readonly class PageFeedbackCommand
{
    /** @param array<string, scalar|null> $filters the filters of the request, which the next cursor repeats */
    public function __construct(
        public Project $project,
        public ?SiteReviewCommentStatus $status,
        public ?Uuid $cardId,
        public PageRequest $page,
        public CreatedSort $sort,
        public array $filters,
    ) {
    }
}
