<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

final readonly class OpenWorkerWaitCommand
{
    /**
     * @param non-empty-list<WaitConditionRequest> $conditions
     */
    public function __construct(
        public Project $project,
        public Uuid $sessionId,
        public array $conditions,
        public int $limitMinutes,
    ) {
    }
}
