<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Bridge\Entity\BridgeClaimRefusal;
use App\Module\Bridge\ValueObject\RefusedRequest;
use App\Module\Bridge\ValueObject\WorkRequestState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * The refusals write with native SQL, so a managed BridgeClaimRefusal can be stale after them.
 *
 * @extends ServiceEntityRepository<BridgeClaimRefusal>
 */
class BridgeClaimRefusalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BridgeClaimRefusal::class);
    }

    /** Keeps one row for the bridge and the request. A row with the same account stays as it is. */
    public function put(Uuid $ownerId, Uuid $bridgeId, Uuid $workRequestId, ?string $account): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO bridge_claim_refusals (id, owner_id, bridge_id, work_request_id, account)
                VALUES (:id, :owner, :bridge, :request, :account)
                ON CONFLICT (bridge_id, work_request_id) DO UPDATE SET account = EXCLUDED.account
                WHERE bridge_claim_refusals.account IS DISTINCT FROM EXCLUDED.account',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'owner' => $ownerId->toRfc4122(),
                'bridge' => $bridgeId->toRfc4122(),
                'request' => $workRequestId->toRfc4122(),
                'account' => $account,
            ],
        );
    }

    /** Removes the refusal of the bridge for the request, because its claim passed the account check. */
    public function deleteOfBridgeAndRequest(Uuid $bridgeId, Uuid $workRequestId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM bridge_claim_refusals WHERE bridge_id = :bridge AND work_request_id = :request',
            ['bridge' => $bridgeId->toRfc4122(), 'request' => $workRequestId->toRfc4122()],
        );
    }

    /** Removes the refusals of every bridge for the request, because it was claimed. */
    public function deleteOfRequest(Uuid $workRequestId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM bridge_claim_refusals WHERE work_request_id = :request',
            ['request' => $workRequestId->toRfc4122()],
        );
    }

    /** Removes every refusal of the bridge, because its version or its accounts changed. */
    public function deleteOfBridge(Uuid $bridgeId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM bridge_claim_refusals WHERE bridge_id = :bridge',
            ['bridge' => $bridgeId->toRfc4122()],
        );
    }

    /** Removes the refusals of the bridge whose request is no longer open. */
    public function deleteOfClosedRequests(Uuid $ownerId, Uuid $bridgeId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM bridge_claim_refusals
                WHERE owner_id = :owner AND bridge_id = :bridge
                AND work_request_id IN (SELECT id FROM work_requests WHERE state <> :open)',
            ['owner' => $ownerId->toRfc4122(), 'bridge' => $bridgeId->toRfc4122(), 'open' => WorkRequestState::Open->value],
        );
    }

    /**
     * The account that each refused bridge named for each of the requests, or null when the claim named none.
     *
     * @param list<Uuid> $workRequestIds
     *
     * @return array<string, array<string, ?string>> account by RFC 4122 bridge id, by RFC 4122 request id
     */
    public function accountsOfRequests(array $workRequestIds): array
    {
        if ([] === $workRequestIds) {
            return [];
        }

        /** @var list<array{work_request_id: string, bridge_id: string, account: ?string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT work_request_id, bridge_id, account FROM bridge_claim_refusals WHERE work_request_id IN (:requests)',
            ['requests' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $workRequestIds)],
            ['requests' => ArrayParameterType::STRING],
        );

        $accounts = [];
        foreach ($rows as $row) {
            $accounts[$row['work_request_id']][$row['bridge_id']] = $row['account'];
        }

        return $accounts;
    }

    /**
     * The open requests of the project that the bridges were refused.
     *
     * @param list<Uuid> $bridgeIds
     *
     * @return list<RefusedRequest>
     */
    public function findOfBridges(Uuid $projectId, array $bridgeIds): array
    {
        if ([] === $bridgeIds) {
            return [];
        }

        /** @var list<array{bridge_id: string, work_request_id: string, subject_type: string, subject_id: string, kind: string, account: ?string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT r.bridge_id, r.work_request_id, w.subject_type, w.subject_id, w.kind, r.account
                FROM bridge_claim_refusals r JOIN work_requests w ON w.id = r.work_request_id
                WHERE w.project_id = :project AND w.state = :open AND r.bridge_id IN (:bridges)
                ORDER BY w.created_at, w.id',
            [
                'project' => $projectId->toRfc4122(),
                'open' => WorkRequestState::Open->value,
                'bridges' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $bridgeIds),
            ],
            ['bridges' => ArrayParameterType::STRING],
        );

        return array_map(static fn (array $row): RefusedRequest => new RefusedRequest(
            Uuid::fromString($row['bridge_id']),
            Uuid::fromString($row['work_request_id']),
            $row['subject_type'],
            Uuid::fromString($row['subject_id']),
            $row['kind'],
            $row['account'],
        ), $rows);
    }

    /** @return iterable<array{bridgeId: string, workRequestId: string, account: ?string}> */
    public function iterateByOwner(Uuid $ownerId): iterable
    {
        $rows = $this->getEntityManager()->getConnection()->iterateAssociative(
            'SELECT bridge_id, work_request_id, account FROM bridge_claim_refusals WHERE owner_id = :owner ORDER BY bridge_id, work_request_id',
            ['owner' => $ownerId->toRfc4122()],
        );

        /** @var array{bridge_id: string, work_request_id: string, account: ?string} $row */
        foreach ($rows as $row) {
            yield ['bridgeId' => $row['bridge_id'], 'workRequestId' => $row['work_request_id'], 'account' => $row['account']];
        }
    }
}
