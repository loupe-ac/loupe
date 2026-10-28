<?php

declare(strict_types=1);

namespace App\Module\Forge\Workflow;

use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\Forge\Service\RepositoryHoldSummaries;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Contract\BlockingRepository;
use App\Module\Workflow\Contract\WorkHold;
use App\Module\Workflow\Contract\WorkHolds;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/** A project holds its new work while a repository has a blocking gap in its last certain check. */
#[AsAlias(WorkHolds::class)]
final readonly class ForgeRepositoryWorkHolds implements WorkHolds
{
    public function __construct(
        private ProjectRepository $projects,
        private ForgeRepositoryRepository $forgeRepositories,
        private RepositoryHoldSummaries $summaries,
    ) {
    }

    #[\Override]
    public function heldProject(Uuid $projectId): ?WorkHold
    {
        $project = $this->projects->find($projectId);
        if (null === $project) {
            return null;
        }
        $summary = $this->summaries->currentUnderKeyShare($project);
        if ($summary->isEmpty()) {
            return null;
        }

        $blocking = [];
        foreach ($this->forgeRepositories->findByProject($project) as $repository) {
            $gaps = $summary->blocking[(string) $repository->id] ?? null;
            if (null !== $gaps) {
                $blocking[] = new BlockingRepository((string) $repository->id, $repository->path, $gaps);
            }
        }

        return new WorkHold($blocking);
    }
}
