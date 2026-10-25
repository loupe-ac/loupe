<?php

declare(strict_types=1);

namespace App\Module\Notification\Repository;

use App\Module\Account\Entity\User;
use App\Module\Notification\Entity\NotificationPreference;
use App\Module\Notification\NotificationChannel;
use App\Module\Notification\NotificationEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<NotificationPreference> */
class NotificationPreferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationPreference::class);
    }

    public function findOneByCell(User $owner, NotificationEvent $event, NotificationChannel $channel): ?NotificationPreference
    {
        return $this->findOneBy(['owner' => $owner, 'event' => $event, 'channel' => $channel]);
    }

    /** @return list<NotificationPreference> */
    public function findByOwner(User $owner): array
    {
        return $this->findBy(['owner' => $owner], ['event' => 'ASC', 'channel' => 'ASC']);
    }

    public function deleteForOwner(Uuid $ownerId): void
    {
        $this->getEntityManager()->createQuery('DELETE FROM '.NotificationPreference::class.' p WHERE IDENTITY(p.owner) = :owner')
            ->setParameter('owner', $ownerId, 'uuid')
            ->execute();
    }
}
