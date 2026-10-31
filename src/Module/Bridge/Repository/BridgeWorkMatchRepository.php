<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Bridge\Entity\BridgeWorkMatch;
use App\Module\Bridge\ValueObject\WorkRequestState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * The matches write with native SQL, so a managed BridgeWorkMatch can be stale after them.
 *
 * @extends ServiceEntityRepository<BridgeWorkMatch>
 */
class BridgeWorkMatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BridgeWorkMatch::class);
    }

    /** Keeps one row for the bridge and the request. A second account replaces the first. */
    public function put(Uuid $ownerId, Uuid $bridgeId, Uuid $workRequestId, string $account): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO bridge_work_matches (id, owner_id, bridge_id, work_request_id, account)
                VALUES (:id, :owner, :bridge, :request, :account)
                ON CONFLICT (bridge_id, work_request_id) DO UPDATE SET account = EXCLUDED.account',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'owner' => $ownerId->toRfc4122(),
                'bridge' => $bridgeId->toRfc4122(),
                'request' => $workRequestId->toRfc4122(),
                'account' => $account,
            ],
        );
    }

    /** Removes the matches of every bridge for the request, because it was claimed or closed. */
    public function deleteOfRequest(Uuid $workRequestId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM bridge_work_matches WHERE work_request_id = :request',
            ['request' => $workRequestId->toRfc4122()],
        );
    }

    /** Removes the matches of the bridge whose request is no longer open. */
    public function deleteOfClosedRequests(Uuid $ownerId, Uuid $bridgeId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM bridge_work_matches
                WHERE owner_id = :owner AND bridge_id = :bridge
                AND work_request_id IN (SELECT id FROM work_requests WHERE state <> :open)',
            ['owner' => $ownerId->toRfc4122(), 'bridge' => $bridgeId->toRfc4122(), 'open' => WorkRequestState::Open->value],
        );
    }

    /**
     * The matches of the bridge with the subject type and the kind of their request.
     *
     * @return list<array{id: string, account: string, subjectType: string, kind: string}>
     */
    public function findOfBridge(Uuid $ownerId, Uuid $bridgeId): array
    {
        /** @var list<array{id: string, account: string, subject_type: string, kind: string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT m.id, m.account, w.subject_type, w.kind
                FROM bridge_work_matches m JOIN work_requests w ON w.id = m.work_request_id
                WHERE m.owner_id = :owner AND m.bridge_id = :bridge',
            ['owner' => $ownerId->toRfc4122(), 'bridge' => $bridgeId->toRfc4122()],
        );

        return array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'account' => $row['account'],
            'subjectType' => $row['subject_type'],
            'kind' => $row['kind'],
        ], $rows);
    }

    /**
     * The account each bridge matched to each of the requests.
     *
     * @param list<Uuid> $workRequestIds
     *
     * @return array<string, array<string, string>> account by RFC 4122 bridge id, by RFC 4122 request id
     */
    public function accountsOfRequests(array $workRequestIds): array
    {
        if ([] === $workRequestIds) {
            return [];
        }

        /** @var list<array{work_request_id: string, bridge_id: string, account: string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT work_request_id, bridge_id, account FROM bridge_work_matches WHERE work_request_id IN (:requests)',
            ['requests' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $workRequestIds)],
            ['requests' => ArrayParameterType::STRING],
        );

        $accounts = [];
        foreach ($rows as $row) {
            $accounts[$row['work_request_id']][$row['bridge_id']] = $row['account'];
        }

        return $accounts;
    }

    /** @param list<string> $ids */
    public function deleteByIds(array $ids): void
    {
        if ([] === $ids) {
            return;
        }

        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM bridge_work_matches WHERE id IN (:ids)',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );
    }
}
