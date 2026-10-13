<?php

declare(strict_types=1);

namespace App\Module\Inbox\Repository;

use App\Module\Account\Entity\User;
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
            ->andWhere('action.bridgeCommandId IN (:ids)')
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $bridgeCommandIds))
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
