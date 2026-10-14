<?php

declare(strict_types=1);

namespace App\Module\Inbox\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<InboxAction> */
class InboxActionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InboxAction::class);
    }

    /** @return list<InboxAction> */
    public function findByItem(InboxItem $item): array
    {
        return $this->createQueryBuilder('action')
            ->andWhere('action.item = :item')
            ->setParameter('item', $item)
            ->orderBy('action.requestedAt', 'ASC')
            ->addOrderBy('action.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<InboxItem> $items
     *
     * @return array<string, list<InboxAction>> item id => actions, oldest first
     */
    public function findByItems(array $items): array
    {
        if ([] === $items) {
            return [];
        }

        /** @var list<InboxAction> $actions */
        $actions = $this->createQueryBuilder('action')
            ->andWhere('action.item IN (:items)')
            ->setParameter('items', $items)
            ->orderBy('action.requestedAt', 'ASC')
            ->addOrderBy('action.id', 'ASC')
            ->getQuery()
            ->getResult();

        $byItem = [];
        foreach ($actions as $action) {
            $byItem[(string) $action->item->id][] = $action;
        }

        return $byItem;
    }

    public function findOneByIdAndItem(Uuid $id, InboxItem $item): ?InboxAction
    {
        return $this->findOneBy(['id' => $id, 'item' => $item]);
    }

    /**
     * With the item and its project loaded, so a caller reads the project with no further query.
     *
     * @param list<Uuid> $bridgeCommandIds
     *
     * @return list<InboxAction>
     */
    public function findByBridgeCommandIds(array $bridgeCommandIds): array
    {
        if ([] === $bridgeCommandIds) {
            return [];
        }

        return $this->createQueryBuilder('action')
            ->addSelect('item', 'project')
            ->join('action.item', 'item')
            ->join('item.project', 'project')
            ->andWhere('action.bridgeCommandId IN (:ids)')
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $bridgeCommandIds))
            ->getQuery()
            ->getResult();
    }

    /**
     * With the item and its project loaded, so a caller reads the project with no further query.
     *
     * @param list<Uuid> $workRequestIds
     *
     * @return list<InboxAction>
     */
    public function findByWorkRequestIds(array $workRequestIds): array
    {
        if ([] === $workRequestIds) {
            return [];
        }

        return $this->createQueryBuilder('action')
            ->addSelect('item', 'project')
            ->join('action.item', 'item')
            ->join('item.project', 'project')
            ->andWhere('action.workRequestId IN (:ids)')
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $workRequestIds))
            ->getQuery()
            ->getResult();
    }

    /**
     * The actions whose bridge command is about one of these runs, or about a run that one of them continues,
     * and the actions whose work request started one of them.
     * One query, with the item and its project loaded.
     *
     * @param list<string> $runIds RFC 4122 strings
     *
     * @return list<InboxAction>
     */
    public function findByRunIds(array $runIds): array
    {
        if ([] === $runIds) {
            return [];
        }

        return $this->createQueryBuilder('action')
            ->addSelect('item', 'project')
            ->join('action.item', 'item')
            ->join('item.project', 'project')
            ->andWhere('action.bridgeCommandId IN (SELECT c.id FROM '.BridgeCommand::class.' c WHERE IDENTITY(c.workerRun) IN (:runs) OR IDENTITY(c.workerRun) IN (SELECT IDENTITY(r.continuesRun) FROM '.WorkerRun::class.' r WHERE r.id IN (:runs)))'
                .' OR action.workRequestId IN (SELECT w.workRequestId FROM '.WorkerRun::class.' w WHERE w.id IN (:runs))')
            ->setParameter('runs', $runIds)
            ->getQuery()
            ->getResult();
    }

    /** @return list<InboxAction> */
    public function findByRequester(User $user): array
    {
        return $this->createQueryBuilder('action')
            ->andWhere('action.requestedBy = :user')
            ->setParameter('user', $user)
            ->orderBy('action.requestedAt', 'ASC')
            ->addOrderBy('action.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
