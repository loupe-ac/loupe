<?php

declare(strict_types=1);

namespace App\Module\Forge\Repository;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositoryCheck;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

/** @extends ServiceEntityRepository<ForgeRepositoryCheck> */
final class ForgeRepositoryCheckRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForgeRepositoryCheck::class);
    }

    public function findOneOfRepository(ForgeRepository $repository): ?ForgeRepositoryCheck
    {
        return $this->findOneBy(['repository' => $repository]);
    }

    /**
     * Reads the rows from the database and skips the identity map, because a
     * row can change in another session while this one holds the project lock.
     *
     * @return array<string, list<array{key: string, mark: string, state: string, gaps: list<string>, parameters: array<string, mixed>}>> the items of each repository, by repository id
     */
    public function itemsByRepositoryOfProject(Project $project): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT repository_id, items FROM forge_repository_checks WHERE project_id = :project',
            ['project' => $project->id],
            ['project' => UuidType::NAME],
        );

        $byRepository = [];
        foreach ($rows as $row) {
            $byRepository[(string) $row['repository_id']] = json_decode((string) $row['items'], true, 512, \JSON_THROW_ON_ERROR);
        }

        return $byRepository;
    }
}
