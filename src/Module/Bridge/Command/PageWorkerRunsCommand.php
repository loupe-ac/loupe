<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\View\WorkerRunListQuery;
use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

final readonly class PageWorkerRunsCommand
{
    public function __construct(
        public Project $project,
        public WorkerRunListQuery $listQuery,
        public int $limit,
        /** The first report time of the last run of the previous page. Null for the first page. */
        public ?\DateTimeImmutable $afterReceivedAt = null,
        public ?Uuid $afterId = null,
    ) {
    }
}
