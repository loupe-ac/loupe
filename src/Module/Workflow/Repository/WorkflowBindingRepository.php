<?php

declare(strict_types=1);

namespace App\Module\Workflow\Repository;

use App\Module\Workflow\Entity\WorkflowBinding;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<WorkflowBinding> */
class WorkflowBindingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowBinding::class);
    }

    public function findOneByProjectId(Uuid $projectId): ?WorkflowBinding
    {
        return $this->findOneBy(['project' => $projectId]);
    }

    /**
     * Reads the stored definition past the identity map, so a caller that holds a lock sees what another transaction committed.
     *
     * @return array<mixed>|null
     */
    public function findDefinitionFresh(Uuid $projectId): ?array
    {
        $definition = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT definition FROM workflow_bindings WHERE project_id = :projectId',
            ['projectId' => $projectId->toRfc4122()],
        );
        if (!\is_string($definition)) {
            return null;
        }
        $decoded = json_decode($definition, true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * The cards of every bound project that sit in a column that is not terminal. A held card counts, because its markers still show.
     *
     * @return list<string> RFC 4122 card ids
     */
    public function findOpenBoundCardIds(): array
    {
        return array_map(strval(...), $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT c.id FROM board_cards c
             JOIN board_columns col ON col.id = c.column_id
             JOIN workflow_bindings b ON b.project_id = c.project_id
             WHERE col.terminal = false
             ORDER BY c.id',
        ));
    }
}
