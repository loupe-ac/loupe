<?php

declare(strict_types=1);

namespace App\Module\Workflow\Entity;

use App\Module\Workflow\Repository\WorkflowProjectChangeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** A project-wide fact of the project changed. A database trigger writes the row. */
#[ORM\Entity(repositoryClass: WorkflowProjectChangeRepository::class)]
#[ORM\Table(name: 'workflow_project_changes')]
class WorkflowProjectChange
{
    public function __construct(
        /** No foreign key, as the project may go. DeleteWorkflowDataOnProjectDeleting deletes the row. */
        #[ORM\Column(name: 'project_id', type: UuidType::NAME)]
        #[ORM\Id]
        public readonly Uuid $projectId,

        #[ORM\Column(name: 'changed_at')]
        public \DateTimeImmutable $changedAt = new \DateTimeImmutable(),
    ) {
    }
}
