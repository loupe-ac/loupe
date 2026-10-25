<?php

declare(strict_types=1);

namespace App\Module\Notification\Repository;

use App\Module\Account\Entity\User;
use App\Module\Notification\Entity\PushDevice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
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

    /** @return list<PushDevice> the devices that stopped and whose warning is still open, the longest stopped first */
    public function findWarningByOwner(User $owner): array
    {
        return array_values($this->createQueryBuilder('d')
            ->andWhere('d.owner = :owner')
            ->andWhere('d.stoppedAt IS NOT NULL')
            ->andWhere('d.warningDismissedAt IS NULL')
            ->orderBy('d.stoppedAt', 'ASC')
            ->addOrderBy('d.createdAt', 'ASC')
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getResult());
    }

    /**
     * Closes the warning of a stopped device. One statement checks the state and
     * writes it, so a re-subscribe between a read and this write cannot leave a
     * dismissed mark on a device that works again.
     *
     * @return bool whether the row changed
     */
    public function dismissWarning(Uuid $id, \DateTimeImmutable $at): bool
    {
        return 0 < $this->getEntityManager()->createQuery('UPDATE '.PushDevice::class.' d SET d.warningDismissedAt = :at WHERE d.id = :id AND d.stoppedAt IS NOT NULL AND d.warningDismissedAt IS NULL')
            ->setParameter('at', $at, Types::DATETIME_IMMUTABLE)
            ->setParameter('id', $id, UuidType::NAME)
            ->execute();
    }

    public function deleteForOwner(Uuid $ownerId): void
    {
        $this->getEntityManager()->createQuery('DELETE FROM '.PushDevice::class.' d WHERE IDENTITY(d.owner) = :owner')
            ->setParameter('owner', $ownerId, 'uuid')
            ->execute();
    }
}
