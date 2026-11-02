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

    public function deleteForOwner(Uuid $ownerId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM api_keys WHERE owner_id = :ownerId',
            ['ownerId' => $ownerId->toRfc4122()],
        );
    }

    /** @return list<ApiKey> keys that end after $now, no later than $until, and have no notice yet */
    public function findExpiringUnnotified(\DateTimeImmutable $now, \DateTimeImmutable $until): array
    {
        /** @var list<ApiKey> $keys */
        $keys = $this->createQueryBuilder('k')
            ->where('k.expiresAt > :now')
            ->andWhere('k.expiresAt <= :until')
            ->andWhere('k.revokedAt IS NULL')
            ->andWhere('k.expiryNoticeSentAt IS NULL')
            ->orderBy('k.expiresAt', 'ASC')
            ->setParameter('now', $now)
            ->setParameter('until', $until)
            ->getQuery()
            ->getResult();

        return $keys;
    }

    /**
     * Marks the expiry notice as sent. One conditional UPDATE, so two workers
     * never both win: only the caller that gets true sends the mail.
     */
    public function claimExpiryNotice(Uuid $keyId, \DateTimeImmutable $now): bool
    {
        return 1 === $this->createQueryBuilder('k')
            ->update()
            ->set('k.expiryNoticeSentAt', ':now')
            ->where('k.id = :id')
            ->andWhere('k.expiryNoticeSentAt IS NULL')
            ->andWhere('k.revokedAt IS NULL')
            ->setParameter('now', $now)
            ->setParameter('id', $keyId, 'uuid')
            ->getQuery()
            ->execute();
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
