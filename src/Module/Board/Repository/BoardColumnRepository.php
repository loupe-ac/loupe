<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\LabelTone;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<BoardColumn>
 */
class BoardColumnRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BoardColumn::class);
    }

    /** @return list<BoardColumn> */
    public function findForProject(Project $project): array
    {
        return $this->findBy(['project' => $project], ['position' => 'ASC']);
    }

    /**
     * The board as the database holds it now, for a writer that holds the
     * project lock. lock() leaves an already loaded column as the request read
     * it, and refresh() refuses to rewrite the readonly project, so the mutable
     * fields are read back by hand.
     *
     * @return list<BoardColumn>
     */
    public function findForProjectFresh(Project $project): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociativeIndexed(
            'SELECT id, label, slug, position, terminal, is_default, tone FROM board_columns WHERE project_id = :projectId',
            ['projectId' => (string) $project->id],
        );

        $columns = [];
        foreach ($this->findForProject($project) as $column) {
            $row = $rows[(string) $column->id] ?? null;
            if (null === $row) {
                continue;
            }
            $column->label = (string) $row['label'];
            $column->slug = (string) $row['slug'];
            $column->position = (int) $row['position'];
            $column->terminal = (bool) $row['terminal'];
            $column->backlog = (bool) $row['is_default'];
            $column->tone = LabelTone::from((string) $row['tone']);
            $columns[] = $column;
        }

        return $columns;
    }

    /**
     * One keyset page of the board in board order, ties broken on the id.
     * The page starts after the last row ($after) when one is given.
     *
     * @param ?array{int, Uuid} $after the position and the id of the last row of the previous page
     *
     * @return list<BoardColumn>
     */
    public function findPageForProject(Project $project, int $limit, ?array $after): array
    {
        $qb = $this->createQueryBuilder('k')
            ->andWhere('k.project = :project')
            ->setParameter('project', $project)
            ->orderBy('k.position', 'ASC')
            ->addOrderBy('k.id', 'ASC')
            ->setMaxResults($limit);

        if (null !== $after) {
            $qb->andWhere('(k.position > :afterPosition OR (k.position = :afterPosition AND k.id > :afterId))')
                ->setParameter('afterPosition', $after[0])
                ->setParameter('afterId', $after[1], UuidType::NAME);
        }

        return array_values($qb->getQuery()->getResult());
    }

    public function findOneByProjectAndSlug(Project $project, string $slug): ?BoardColumn
    {
        return $this->findOneBy(['project' => $project, 'slug' => $slug]);
    }

    /** @return list<BoardColumn> the columns the board draws, which leaves the Backlog out */
    public function findBoardColumns(Project $project): array
    {
        return $this->findBy(['project' => $project, 'backlog' => false], ['position' => 'ASC']);
    }

    /** From a raw route parameter, for a MapEntity expression. */
    public function findBacklogForProjectId(string $projectId): ?BoardColumn
    {
        return Uuid::isValid($projectId)
            ? $this->findOneBy(['project' => Uuid::fromString($projectId), 'backlog' => true])
            : null;
    }

    public function findFirstTerminalForProjectId(string $projectId): ?BoardColumn
    {
        return Uuid::isValid($projectId)
            ? $this->findOneBy(['project' => Uuid::fromString($projectId), 'terminal' => true], ['position' => 'ASC'])
            : null;
    }

    /** A URL that pairs one project with another project's column is a 404. */
    public function findOneByIdAndProjectId(string $columnId, string $projectId): ?BoardColumn
    {
        if (!Uuid::isValid($columnId) || !Uuid::isValid($projectId)) {
            return null;
        }

        return $this->createQueryBuilder('k')
            ->andWhere('k.id = :columnId')
            ->andWhere('k.project = :projectId')
            ->setParameter('columnId', Uuid::fromString($columnId), UuidType::NAME)
            ->setParameter('projectId', Uuid::fromString($projectId), UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
