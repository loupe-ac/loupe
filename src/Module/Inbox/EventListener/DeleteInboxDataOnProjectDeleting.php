<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Project\Event\ProjectDeleting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Bulk-deletes the asks, the bridge outages, the OpenRouter key notices, the repository misfits and the items of a project,
 * inside ProjectDeleter's transaction. The foreign keys of the three link tables cascade from both
 * parents, so the database removes the links. The foreign key of inbox_actions cascades from
 * inbox_items, so deleting the items removes their actions.
 */
#[AsEventListener]
final readonly class DeleteInboxDataOnProjectDeleting
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ProjectDeleting $event): void
    {
        $this->em->createQuery(
            'DELETE App\Module\Inbox\Entity\InboxAsk a WHERE a.project = :project',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Inbox\Entity\InboxBridgeOutage o WHERE o.project = :project',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Inbox\Entity\InboxOpenRouterKeyNotice n WHERE n.project = :project',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Inbox\Entity\InboxRepositoryMisfit m WHERE m.project = :project',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Inbox\Entity\InboxItem i WHERE i.project = :project',
        )->setParameter('project', $event->project)->execute();
    }
}
