<?php

declare(strict_types=1);

namespace App\Module\Forge\Repository;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositoryCheck;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

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

    /** @return array<string, true> the ids of the repositories whose stored check holds items, as keys */
    public function repositoryIdsWithItems(): array
    {
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT repository_id FROM forge_repository_checks WHERE json_array_length(items) > 0',
        );

        return array_fill_keys(array_map(strval(...), $ids), true);
    }

    /**
     * Takes the next read ticket of a repository in one statement, so two
     * overlapping checks get distinct tickets. The first check inserts the row.
     */
    public function takeReadTicket(ForgeRepository $repository): int
    {
        $ticket = $this->getEntityManager()->getConnection()->fetchOne(
            <<<'SQL'
                INSERT INTO forge_repository_checks (id, repository_id, project_id, items, next_read_ticket, accepted_read_ticket)
                VALUES (:id, :repository, :project, '[]', 1, 0)
                ON CONFLICT (repository_id) DO UPDATE SET next_read_ticket = forge_repository_checks.next_read_ticket + 1
                RETURNING next_read_ticket
                SQL,
            [
                'id' => Uuid::v7(),
                'repository' => $repository->id,
                'project' => $repository->project->id,
            ],
            ['id' => UuidType::NAME, 'repository' => UuidType::NAME, 'project' => UuidType::NAME],
        );

        return \is_int($ticket) ? $ticket : throw new \LogicException('The ticket statement returns a row.');
    }
}
