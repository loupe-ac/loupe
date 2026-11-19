<?php

declare(strict_types=1);

namespace App\Module\Workflow\Repository;

use App\Module\Workflow\Entity\WorkflowProjectChange;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<WorkflowProjectChange> */
class WorkflowProjectChangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowProjectChange::class);
    }

    /** One statement, so it joins a caller's transaction. */
    public function clear(Uuid $projectId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM workflow_project_changes WHERE project_id = :project',
            ['project' => $projectId->toRfc4122()],
        );
    }
}
