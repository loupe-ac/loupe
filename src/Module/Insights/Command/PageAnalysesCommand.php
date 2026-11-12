<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

final readonly class PageAnalysesCommand
{
    public function __construct(
        public Project $project,
        public int $limit,
        /** The creation time and the id of the last analysis of the previous page. */
        public ?\DateTimeImmutable $afterCreatedAt = null,
        public ?Uuid $afterId = null,
    ) {
    }
}
