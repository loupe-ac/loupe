<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Project\Entity\Project;

final readonly class MarkCommentIdsAddressedCommand
{
    /**
     * @param Project      $project    the project the caller may act on; a comment outside it counts as not found
     * @param list<string> $commentIds root comment ids as the caller sent them, validated by the handler
     */
    public function __construct(
        public Project $project,
        public array $commentIds,
    ) {
    }
}
