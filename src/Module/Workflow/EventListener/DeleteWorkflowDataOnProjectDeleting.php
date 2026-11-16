<?php

declare(strict_types=1);

namespace App\Module\Workflow\EventListener;

use App\Module\Project\Event\ProjectDeleting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Bulk-deletes the rule states, the pending baselines, the slot links, then the setting rows, then the binding, then the change marks, of a project inside ProjectDeleter's transaction. */
#[AsEventListener]
final readonly class DeleteWorkflowDataOnProjectDeleting
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ProjectDeleting $event): void
    {
        $this->em->createQuery(
            'DELETE App\Module\Workflow\Entity\WorkflowRuleState s WHERE s.project = :project',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Workflow\Entity\WorkflowPendingBaseline p WHERE p.project = :project',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Workflow\Entity\WorkflowSlotLink l WHERE l.project = :project',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Workflow\Entity\WorkflowSettingOverride o WHERE o.project = :project',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Workflow\Entity\WorkflowRepositorySetting s WHERE s.project = :project',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Workflow\Entity\WorkflowBinding b WHERE b.project = :project',
        )->setParameter('project', $event->project)->execute();

        // Last, because the deletes above write marks.
        $this->em->createQuery(
            'DELETE App\Module\Workflow\Entity\WorkflowProjectChange c WHERE c.projectId = :projectId',
        )->setParameter('projectId', $event->project->requireId(), UuidType::NAME)->execute();

        $this->em->getConnection()->executeStatement(
            'DELETE FROM workflow_card_changes WHERE card_id IN (SELECT id FROM board_cards WHERE project_id = :project)',
            ['project' => $event->project->requireId()->toRfc4122()],
        );
    }
}
