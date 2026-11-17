<?php

declare(strict_types=1);

namespace App\Module\Workflow\EventListener;

use App\Module\Project\Event\ProjectDeleting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Deletes the change marks of a project after every other listener, because their deletes write marks.
 * The mark tables have no foreign keys, so a mark of a card that no longer exists is an orphan.
 */
#[AsEventListener(priority: -1000)]
final readonly class DeleteWorkflowChangeMarksOnProjectDeleting
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ProjectDeleting $event): void
    {
        $this->em->createQuery(
            'DELETE App\Module\Workflow\Entity\WorkflowProjectChange c WHERE c.projectId = :projectId',
        )->setParameter('projectId', $event->project->requireId(), UuidType::NAME)->execute();

        $this->em->getConnection()->executeStatement(
            'DELETE FROM workflow_card_changes WHERE card_id NOT IN (SELECT id FROM board_cards)',
        );
    }
}
