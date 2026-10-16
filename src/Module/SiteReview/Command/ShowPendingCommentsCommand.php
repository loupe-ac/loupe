<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

final readonly class ShowPendingCommentsCommand
{
    public function __construct(
        public Project $project,
        /** @var list<Uuid>|null the comments to list, or null for every pending comment of the project */
        public ?array $commentIds = null,
    ) {
    }
}
