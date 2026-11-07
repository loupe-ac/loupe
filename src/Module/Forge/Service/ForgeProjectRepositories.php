<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\Workflow\Contract\ProjectRepositories;
use App\Module\Workflow\Contract\RepositoryRef;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(ProjectRepositories::class)]
final readonly class ForgeProjectRepositories implements ProjectRepositories
{
    public function __construct(
        private ForgeRepositoryRepository $forgeRepositories,
    ) {
    }

    #[\Override]
    public function of(Uuid $projectId): array
    {
        return array_values(array_map(
            static fn (ForgeRepository $repository): RepositoryRef => new RepositoryRef(
                $repository->id ?? throw new \LogicException('The repository is not flushed.'),
                $repository->forge,
                $repository->externalId,
                $repository->path,
            ),
            $this->forgeRepositories->findByProjectId($projectId),
        ));
    }
}
