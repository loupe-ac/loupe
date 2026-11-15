<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

final readonly class ListReportRowsCommand
{
    /** @param list<Uuid> $ids the analyses a later page shows */
    public function __construct(
        public Project $project,
        public array $ids,
        /** Counts the analyses created after this time. Null counts none. */
        public ?\DateTimeImmutable $since = null,
    ) {
    }
}
