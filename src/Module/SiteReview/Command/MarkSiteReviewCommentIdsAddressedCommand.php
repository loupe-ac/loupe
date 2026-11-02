<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Project\Entity\Project;

final readonly class MarkSiteReviewCommentIdsAddressedCommand
{
    /**
     * @param Project      $project    the project the caller may act on; a comment outside it counts as unknown
     * @param list<string> $commentIds feedback ids as the caller sent them, validated by the handler
     */
    public function __construct(
        public Project $project,
        public array $commentIds,
    ) {
    }
}
