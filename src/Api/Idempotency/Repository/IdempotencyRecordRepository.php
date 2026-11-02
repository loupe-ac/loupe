<?php

declare(strict_types=1);

namespace App\Api\Idempotency\Repository;

use App\Api\Idempotency\Entity\IdempotencyRecord;
use App\Api\Idempotency\IdempotencyState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Every write goes through the DBAL connection. A refused request can close the
 * EntityManager, and a claim or a stored answer must survive that.
 *
 * @extends ServiceEntityRepository<IdempotencyRecord>
 */
class IdempotencyRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IdempotencyRecord::class);
    }

    /**
     * Claims the key in a statement of its own. Returns false when a live row holds
     * the key already. A row past its end is replaced, because the purge task may
     * not have run yet.
     */
    public function claim(string $credential, string $key, Uuid $ownerId, string $requestHash, \DateTimeImmutable $now, \DateTimeImmutable $expiresAt): ?Uuid
    {
        $connection = $this->getEntityManager()->getConnection();
        $connection->executeStatement(
            'DELETE FROM api_idempotency_records WHERE credential = :credential AND idempotency_key = :key AND expires_at <= :now',
            ['credential' => $credential, 'key' => $key, 'now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        );

        $id = Uuid::v7();
        $inserted = $connection->executeStatement(
            'INSERT INTO api_idempotency_records (id, credential, idempotency_key, owner_id, request_hash, state, created_at, expires_at)
             VALUES (:id, :credential, :key, :owner, :hash, :state, :now, :expires)
             ON CONFLICT (credential, idempotency_key) DO NOTHING',
            [
                'id' => $id,
                'credential' => $credential,
                'key' => $key,
                'owner' => $ownerId,
                'hash' => $requestHash,
                'state' => IdempotencyState::Running->value,
                'now' => $now,
                'expires' => $expiresAt,
            ],
            ['id' => 'uuid', 'owner' => 'uuid', 'now' => Types::DATETIME_IMMUTABLE, 'expires' => Types::DATETIME_IMMUTABLE],
        );

        return 1 === $inserted ? $id : null;
    }

    /** @return array{requestHash: string, state: IdempotencyState, status: ?int, headers: array<string, string>, body: ?string}|null */
    public function findHeld(string $credential, string $key): ?array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT request_hash, state, status, headers, body FROM api_idempotency_records WHERE credential = :credential AND idempotency_key = :key',
            ['credential' => $credential, 'key' => $key],
        );
        if (false === $row) {
            return null;
        }

        $headers = null === $row['headers'] ? [] : json_decode((string) $row['headers'], true, flags: \JSON_THROW_ON_ERROR);

        return [
            'requestHash' => (string) $row['request_hash'],
            'state' => IdempotencyState::from((string) $row['state']),
            'status' => null === $row['status'] ? null : (int) $row['status'],
            'headers' => \is_array($headers) ? $headers : [],
            'body' => null === $row['body'] ? null : (string) $row['body'],
        ];
    }

    /** @param array<string, string> $headers */
    public function finish(Uuid $id, int $status, array $headers, string $body): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE api_idempotency_records SET state = :state, status = :status, headers = :headers, body = :body WHERE id = :id',
            [
                'state' => IdempotencyState::Finished->value,
                'status' => $status,
                'headers' => $headers,
                'body' => $body,
                'id' => $id,
            ],
            ['headers' => Types::JSON, 'id' => 'uuid'],
        );
    }

    public function deleteExpired(\DateTimeImmutable $now): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM api_idempotency_records WHERE expires_at <= :now',
            ['now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    public function deleteForOwner(Uuid $ownerId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM api_idempotency_records WHERE owner_id = :owner',
            ['owner' => $ownerId],
            ['owner' => 'uuid'],
        );
    }
}
