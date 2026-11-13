<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Bridge\Contract\InitRuns;
use App\Module\Bridge\Repository\WorkerRunRepository;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(InitRuns::class)]
final readonly class OpenInitRuns implements InitRuns
{
    public function __construct(
        private WorkerRunRepository $workerRuns,
    ) {
    }

    #[\Override]
    public function isOpen(Uuid $projectId): bool
    {
        return $this->workerRuns->hasOpenInit($projectId);
    }
}
