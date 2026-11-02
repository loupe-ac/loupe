<?php

declare(strict_types=1);

namespace App\Module\OAuth\Repository;

use App\Module\Account\Entity\User;
use App\Module\OAuth\Entity\ApiKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<ApiKey> */
class ApiKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiKey::class);
    }

    public function findByKeyHash(string $keyHash): ?ApiKey
    {
        return $this->findOneBy(['keyHash' => $keyHash]);
    }

    /** @return list<ApiKey> newest first */
    public function findByOwner(User $owner): array
    {
        return $this->findBy(['owner' => $owner], ['createdAt' => 'DESC']);
    }

    /**
     * Stamps the use time unless the stored one is newer than $threshold. One
     * conditional UPDATE, so a burst of requests writes once and the write
     * carries nothing else the request has pending.
     */
    public function recordUse(Uuid $keyId, \DateTimeImmutable $now, \DateTimeImmutable $threshold): void
    {
        $this->createQueryBuilder('k')
            ->update()
            ->set('k.lastUsedAt', ':now')
            ->where('k.id = :id')
            ->andWhere('k.lastUsedAt IS NULL OR k.lastUsedAt < :threshold')
            ->setParameter('now', $now)
            ->setParameter('id', $keyId, 'uuid')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->execute();
    }
}
