<?php

declare(strict_types=1);

namespace App\Module\Notification\Repository;

use App\Module\Account\Entity\User;
use App\Module\Notification\Entity\PushDevice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<PushDevice> */
class PushDeviceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PushDevice::class);
    }

    public function findOneByEndpoint(string $endpoint): ?PushDevice
    {
        return $this->findOneBy(['endpoint' => $endpoint]);
    }

    public function findOneByOwnerAndTokenHash(User $owner, string $deviceTokenHash): ?PushDevice
    {
        return $this->findOneBy(['owner' => $owner, 'deviceTokenHash' => $deviceTokenHash]);
    }

    /** The row, only when the user owns it. A row of someone else reads as missing. */
    public function findOneOwnedBy(User $owner, Uuid $id): ?PushDevice
    {
        return $this->findOneBy(['id' => $id, 'owner' => $owner]);
    }

    /** @return list<PushDevice> */
    public function findByOwner(User $owner): array
    {
        return $this->findBy(['owner' => $owner], ['createdAt' => 'ASC']);
    }

    /** @return list<PushDevice> the devices that still receive a push, oldest first */
    public function findActiveByOwner(User $owner): array
    {
        return $this->findBy(['owner' => $owner, 'stoppedAt' => null], ['createdAt' => 'ASC']);
    }

    public function deleteForOwner(Uuid $ownerId): void
    {
        $this->getEntityManager()->createQuery('DELETE FROM '.PushDevice::class.' d WHERE IDENTITY(d.owner) = :owner')
            ->setParameter('owner', $ownerId, 'uuid')
            ->execute();
    }
}
