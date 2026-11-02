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

        return [
            'requestHash' => (string) $row['request_hash'],
            'state' => IdempotencyState::from((string) $row['state']),
            'status' => null === $row['status'] ? null : (int) $row['status'],
            'headers' => self::decodeHeaders($row['headers']),
            'body' => null === $row['body'] ? null : (string) $row['body'],
        ];
    }

    /**
     * Every row of the owner, one at a time, because a stored body can be large.
     *
     * @return iterable<array{id: string, credential: string, idempotencyKey: string, requestHash: string, state: IdempotencyState, status: ?int, headers: array<string, string>, body: ?string, createdAt: \DateTimeImmutable, expiresAt: \DateTimeImmutable}>
     */
    public function iterateByOwner(Uuid $ownerId): iterable
    {
        $rows = $this->getEntityManager()->getConnection()->iterateAssociative(
            'SELECT id, credential, idempotency_key, request_hash, state, status, headers, body, created_at, expires_at
             FROM api_idempotency_records WHERE owner_id = :owner ORDER BY created_at, id',
            ['owner' => $ownerId],
            ['owner' => 'uuid'],
        );

        foreach ($rows as $row) {
            yield [
                'id' => (string) $row['id'],
                'credential' => (string) $row['credential'],
                'idempotencyKey' => (string) $row['idempotency_key'],
                'requestHash' => (string) $row['request_hash'],
                'state' => IdempotencyState::from((string) $row['state']),
                'status' => null === $row['status'] ? null : (int) $row['status'],
                'headers' => self::decodeHeaders($row['headers']),
                'body' => null === $row['body'] ? null : (string) $row['body'],
                'createdAt' => new \DateTimeImmutable((string) $row['created_at']),
                'expiresAt' => new \DateTimeImmutable((string) $row['expires_at']),
            ];
        }
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

    public function release(Uuid $id): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM api_idempotency_records WHERE id = :id AND state = :state',
            ['id' => $id, 'state' => IdempotencyState::Running->value],
            ['id' => 'uuid'],
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

    /** @return array<string, string> */
    private static function decodeHeaders(mixed $headers): array
    {
        $decoded = null === $headers ? [] : json_decode((string) $headers, true, flags: \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }
}
