<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\CardHold;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The claim, the renewal and the lapse sweep write with native SQL, so a
 * managed WorkRequest can be stale after them. Read it again through
 * findOneLocked, which refreshes.
 *
 * @extends ServiceEntityRepository<WorkRequest>
 */
class WorkRequestRepository extends ServiceEntityRepository
{
    /** A card request whose card has a hold. The lapse sweep leaves its claim alone. */
    private const string HELD_CARD = <<<'SQL'
        (work_requests.subject_type = 'card' AND EXISTS (
            SELECT 1 FROM bridge_card_holds h
            WHERE h.project_id = work_requests.project_id AND h.card_id = work_requests.subject_id))
        SQL;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkRequest::class);
    }

    /** Claims an open request for the bridge. Answers false when the request is not open, so a second claim loses. */
    public function claim(Uuid $id, Uuid $bridgeId, Uuid $token, \DateTimeImmutable $leaseUntil): bool
    {
        $sql = <<<'SQL'
            UPDATE work_requests
            SET state = :claimed, bridge_id = :bridge, claim_token = :token, lease_until = :leaseUntil, claims = claims + 1
            WHERE id = :id AND state = :open
            RETURNING id
            SQL;

        return false !== $this->getEntityManager()->getConnection()->executeQuery(
            $sql,
            [
                'claimed' => WorkRequestState::Claimed->value,
                'bridge' => $bridgeId->toRfc4122(),
                'token' => $token->toRfc4122(),
                'leaseUntil' => $leaseUntil,
                'id' => $id->toRfc4122(),
                'open' => WorkRequestState::Open->value,
            ],
            ['leaseUntil' => Types::DATETIME_IMMUTABLE],
        )->fetchOne();
    }

    /** Locked until the transaction ends, and read fresh even when the request is already managed. */
    public function findOneLocked(Uuid $id): ?WorkRequest
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.id = :id')
            ->setParameter('id', $id, UuidType::NAME)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /**
     * Extends the lease of each claim that the owner's bridge still holds
     * with its token. The rows lock in id order, like the lapse sweep, so the
     * two cannot deadlock.
     *
     * @param list<array{Uuid, Uuid}> $claims pairs of the request id and the claim token
     *
     * @return list<string> the RFC 4122 ids of the renewed requests
     */
    public function renewLeases(string $ownerId, Uuid $bridgeId, array $claims, \DateTimeImmutable $leaseUntil): array
    {
        if ([] === $claims) {
            return [];
        }

        $sql = <<<'SQL'
            WITH target AS MATERIALIZED (
                SELECT w.id
                FROM work_requests w
                JOIN unnest(CAST(:ids AS uuid[]), CAST(:tokens AS uuid[])) AS held(id, token)
                  ON w.id = held.id AND w.claim_token = held.token
                JOIN projects p ON p.id = w.project_id
                WHERE w.bridge_id = :bridge
                  AND w.state = :claimed
                  AND p.owner_id = :owner
                ORDER BY w.id
                FOR UPDATE OF w
            )
            UPDATE work_requests
            SET lease_until = :leaseUntil
            FROM target
            WHERE work_requests.id = target.id
            RETURNING work_requests.id
            SQL;

        $array = static fn (array $uuids): string => '{'.implode(',', array_map(static fn (Uuid $uuid): string => $uuid->toRfc4122(), $uuids)).'}';

        /** @var list<string> $ids */
        $ids = $this->getEntityManager()->getConnection()->executeQuery(
            $sql,
            [
                'leaseUntil' => $leaseUntil,
                'ids' => $array(array_column($claims, 0)),
                'tokens' => $array(array_column($claims, 1)),
                'bridge' => $bridgeId->toRfc4122(),
                'claimed' => WorkRequestState::Claimed->value,
                'owner' => $ownerId,
            ],
            ['leaseUntil' => Types::DATETIME_IMMUTABLE],
        )->fetchFirstColumn();

        return $ids;
    }

    /**
     * The open requests of the projects that a bridge with these capabilities
     * can run, oldest first.
     *
     * A request that names a target bridge is offered to that bridge alone.
     *
     * @param list<string> $projectIds     RFC 4122 ids, as Bridge::$projects holds them
     * @param list<string> $capabilities
     * @param list<string> $heldProjectIds RFC 4122 ids of the projects that hold their new work. Their requests are left out.
     *
     * @return list<WorkRequest>
     */
    public function findOpenOffers(Uuid $bridgeId, array $projectIds, array $capabilities, int $limit, array $heldProjectIds): array
    {
        if ([] === $projectIds) {
            return [];
        }

        $qb = $this->createQueryBuilder('w')
            ->andWhere('w.project IN (:projects)')
            ->andWhere('w.state = :open')
            ->andWhere('w.targetBridgeId IS NULL OR w.targetBridgeId = :bridge')
            ->andWhere('NOT (w.subjectType = :card AND EXISTS (SELECT 1 FROM '.CardHold::class.' h WHERE h.project = w.project AND h.cardId = w.subjectId))')
            ->setParameter('bridge', $bridgeId, UuidType::NAME)
            ->setParameter('projects', $projectIds)
            ->setParameter('open', WorkRequestState::Open->value)
            ->setParameter('card', WorkSubject::CARD)
            ->orderBy('w.createdAt', 'ASC')
            ->addOrderBy('w.id', 'ASC')
            ->setMaxResults($limit);
        if ([] !== $heldProjectIds) {
            $qb->andWhere('w.project NOT IN (:heldProjects)')
                ->setParameter('heldProjects', $heldProjectIds);
        }
        if ([] === $capabilities) {
            $qb->andWhere('w.capability IS NULL');
        } else {
            $subjectPrompt = \in_array(Bridge::CAPABILITY_APP_PROMPTS, $capabilities, true)
                ? ' OR (w.prompt IS NOT NULL AND w.capability LIKE :subjectPrefix)'
                : '';
            $qb->andWhere('w.capability IS NULL OR w.capability IN (:capabilities)'.$subjectPrompt)
                ->setParameter('capabilities', $capabilities);
            if ('' !== $subjectPrompt) {
                $qb->setParameter('subjectPrefix', Bridge::SUBJECT_CAPABILITY_PREFIX.'%');
            }
        }

        return array_values($qb->getQuery()->getResult());
    }

    /**
     * The projects that have a claim whose lease lapsed.
     *
     * @return list<string> RFC 4122 ids
     */
    public function findProjectIdsOfLapsedClaims(\DateTimeImmutable $now): array
    {
        /** @var list<string> $ids */
        $ids = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT DISTINCT project_id FROM work_requests WHERE state = :claimed AND lease_until <= :now',
            ['claimed' => WorkRequestState::Claimed->value, 'now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        )->fetchFirstColumn();

        return $ids;
    }

    /**
     * Opens again every claimed request whose lease lapsed. It skips a row
     * that a renewal or a settlement holds, and the next sweep reads it again.
     * Call it inside a transaction: it share-locks the project of each request
     * first, so a hold that commits before the reopen keeps it claimed.
     * A request of a project that holds its new work stays claimed too.
     *
     * @param list<string> $heldProjectIds RFC 4122 ids, read in the same transaction as this call
     *
     * @return list<WorkRequest> the reopened requests, read fresh
     */
    public function reopenLapsed(\DateTimeImmutable $now, array $heldProjectIds): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $lockedProjects = $connection->executeQuery(
            'SELECT id FROM projects WHERE id IN (
                SELECT project_id FROM work_requests
                WHERE state = :claimed AND lease_until <= :now
            ) ORDER BY id FOR SHARE',
            ['claimed' => WorkRequestState::Claimed->value, 'now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        )->fetchFirstColumn();

        $sql = <<<'SQL'
            WITH target AS MATERIALIZED (
                SELECT id
                FROM work_requests
                WHERE state = :claimed AND lease_until <= :now AND NOT %s%s
                    AND project_id IN (:lockedProjects)
                ORDER BY id
                FOR UPDATE SKIP LOCKED
            )
            UPDATE work_requests
            SET state = :open, bridge_id = NULL, claim_token = NULL, lease_until = NULL, reopened_at = :now
            FROM target
            WHERE work_requests.id = target.id
            RETURNING work_requests.id
            SQL;
        $sql = \sprintf($sql, self::HELD_CARD, [] === $heldProjectIds ? '' : ' AND project_id NOT IN (:heldProjects)');
        $parameters = ['open' => WorkRequestState::Open->value, 'claimed' => WorkRequestState::Claimed->value, 'now' => $now, 'lockedProjects' => [] === $lockedProjects ? [null] : $lockedProjects];
        $types = ['now' => Types::DATETIME_IMMUTABLE, 'lockedProjects' => ArrayParameterType::STRING];
        if ([] !== $heldProjectIds) {
            $parameters['heldProjects'] = $heldProjectIds;
            $types['heldProjects'] = ArrayParameterType::STRING;
        }

        $ids = $connection->executeQuery($sql, $parameters, $types)->fetchFirstColumn();
        if ([] === $ids) {
            return [];
        }

        return array_values($this->createQueryBuilder('w')
            ->andWhere('w.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('w.createdAt', 'ASC')
            ->addOrderBy('w.id', 'ASC')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult());
    }

    /**
     * The open requests about a subject other than a card that waited for a
     * bridge since the deadline or longer, oldest first. A reopen restarts the wait, and a work hold stops it.
     *
     * @return list<WorkRequest>
     */
    public function findOpenOfOtherSubjectsBefore(\DateTimeImmutable $deadline): array
    {
        return array_values($this->createQueryBuilder('w')
            ->andWhere('w.state = :open')
            ->andWhere('w.subjectType <> :card')
            ->andWhere('w.workHoldSince IS NULL')
            ->andWhere('COALESCE(w.reopenedAt, w.createdAt) <= :deadline')
            ->setParameter('open', WorkRequestState::Open->value)
            ->setParameter('card', WorkSubject::CARD)
            ->setParameter('deadline', $deadline, Types::DATETIME_IMMUTABLE)
            ->orderBy('w.createdAt', 'ASC')
            ->addOrderBy('w.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * The open requests about a card whose rule id starts with the prefix and that no work hold marked, oldest first.
     * The workflow engine leaves such a request alone, so the caller expires it.
     *
     * @return list<WorkRequest>
     */
    public function findOpenOfCardsByRulePrefix(string $prefix): array
    {
        return array_values($this->createQueryBuilder('w')
            ->andWhere('w.state = :open')
            ->andWhere('w.subjectType = :card')
            ->andWhere('w.ruleId LIKE :prefix')
            ->andWhere('w.workHoldSince IS NULL')
            ->setParameter('open', WorkRequestState::Open->value)
            ->setParameter('card', WorkSubject::CARD)
            ->setParameter('prefix', addcslashes($prefix, '%_\\').'%')
            ->orderBy('w.createdAt', 'ASC')
            ->addOrderBy('w.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * The open requests of a kind whose rule id starts with the prefix and that waited for a bridge
     * since the deadline or longer, oldest first. A reopen restarts the wait, and a work hold stops it.
     *
     * @return list<WorkRequest>
     */
    public function findOpenOfRulePrefixAndKindBefore(string $rulePrefix, string $kind, \DateTimeImmutable $deadline): array
    {
        return array_values($this->createQueryBuilder('w')
            ->andWhere('w.state = :open')
            ->andWhere('w.kind = :kind')
            ->andWhere('w.ruleId LIKE :prefix')
            ->andWhere('w.workHoldSince IS NULL')
            ->andWhere('COALESCE(w.reopenedAt, w.createdAt) <= :deadline')
            ->setParameter('open', WorkRequestState::Open->value)
            ->setParameter('kind', $kind)
            ->setParameter('prefix', addcslashes($rulePrefix, '%_\\').'%')
            ->setParameter('deadline', $deadline, Types::DATETIME_IMMUTABLE)
            ->orderBy('w.createdAt', 'ASC')
            ->addOrderBy('w.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * Restarts the timeout of each open request of the cards, in one statement, so it joins a caller's transaction.
     * A managed request is stale after it.
     *
     * @param non-empty-list<Uuid> $cardIds
     */
    public function restartClockOfOpenForCards(Uuid $projectId, array $cardIds, \DateTimeImmutable $now): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE work_requests SET reopened_at = :now
             WHERE project_id = :project AND state = :open AND subject_type = :card AND subject_id IN (:cardIds)',
            [
                'now' => $now,
                'project' => $projectId->toRfc4122(),
                'open' => WorkRequestState::Open->value,
                'card' => WorkSubject::CARD,
                'cardIds' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $cardIds),
            ],
            ['now' => Types::DATETIME_IMMUTABLE, 'cardIds' => ArrayParameterType::STRING],
        );
    }

    /**
     * The open requests about the cards of the project, oldest first.
     *
     * @return list<WorkRequest>
     */
    public function findOpenCardRequestsOfProject(Project $project): array
    {
        return array_values($this->createQueryBuilder('w')
            ->andWhere('w.project = :project')
            ->andWhere('w.state = :open')
            ->andWhere('w.subjectType = :card')
            ->setParameter('project', $project)
            ->setParameter('open', WorkRequestState::Open->value)
            ->setParameter('card', WorkSubject::CARD)
            ->orderBy('w.createdAt', 'ASC')
            ->addOrderBy('w.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /** Marks each open request of the project that the hold found, in one statement, so it joins a caller's transaction. A managed request is stale after it. */
    public function markOpenHeldForProject(Uuid $projectId, \DateTimeImmutable $now): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE work_requests SET work_hold_since = :now WHERE project_id = :project AND state = :open AND work_hold_since IS NULL',
            ['now' => $now, 'project' => $projectId->toRfc4122(), 'open' => WorkRequestState::Open->value],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    /** Restarts the timeout of each open request of the project that the hold marked, and clears the mark, in one statement. A managed request is stale after it. */
    public function restartClockOfHeldForProject(Uuid $projectId, \DateTimeImmutable $now): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE work_requests SET reopened_at = :now, work_hold_since = NULL WHERE project_id = :project AND state = :open AND work_hold_since IS NOT NULL',
            ['now' => $now, 'project' => $projectId->toRfc4122(), 'open' => WorkRequestState::Open->value],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * Serialises the opens of one kind on one subject until the transaction
     * ends, so a second open reads the first instead of tripping the unique index.
     */
    public function lockLive(WorkSubject $subject, string $kind): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtext(?))',
            ['work_request:'.$subject->type.':'.$subject->id->toRfc4122().':'.$kind],
        );
    }

    /** The rule of the live request of the subject and kind, or null when none is live. Reads the state alone, like the unique index. */
    public function liveRuleId(WorkSubject $subject, string $kind): ?string
    {
        /** @var array{ruleId: string}|null $row */
        $row = $this->ofSubject($subject)
            ->select('w.ruleId')
            ->andWhere('w.kind = :kind')
            ->andWhere('w.state IN (:live)')
            ->setParameter('kind', $kind)
            ->setParameter('live', [WorkRequestState::Open->value, WorkRequestState::Claimed->value])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row['ruleId'] ?? null;
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return array<string, WorkRequest> keyed by the RFC 4122 request id
     */
    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<WorkRequest> $requests */
        $requests = $this->createQueryBuilder('w')
            ->andWhere('w.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids))
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($requests as $request) {
            $byId[(string) $request->id] = $request;
        }

        return $byId;
    }

    /** The request with the id, when it belongs to the subject in the project. A run report names its request unchecked. */
    public function findOneOfSubject(Uuid $id, Project $project, WorkSubject $subject): ?WorkRequest
    {
        return $this->ofSubject($subject)
            ->andWhere('w.id = :id')
            ->andWhere('w.project = :project')
            ->setParameter('id', $id, UuidType::NAME)
            ->setParameter('project', $project)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The open requests of the card, oldest first.
     *
     * @return list<WorkRequest>
     */
    public function findOpenForCard(Uuid $cardId): array
    {
        return array_values($this->ofSubject(WorkSubject::card($cardId))
            ->andWhere('w.state = :open')
            ->setParameter('open', WorkRequestState::Open->value)
            ->orderBy('w.createdAt', 'ASC')
            ->addOrderBy('w.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * The open and claimed requests of the card, oldest first.
     *
     * @return list<WorkRequest>
     */
    public function findLiveForCard(Uuid $cardId): array
    {
        return array_values($this->ofSubject(WorkSubject::card($cardId))
            ->andWhere('w.state IN (:live)')
            ->setParameter('live', [WorkRequestState::Open->value, WorkRequestState::Claimed->value])
            ->orderBy('w.createdAt', 'ASC')
            ->addOrderBy('w.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * The open and claimed requests of the cards, in one query, oldest first.
     *
     * @param list<string> $cardIds RFC 4122 ids
     *
     * @return list<array{card_id: string, kind: string, created_at: string}>
     */
    public function findLiveRowsForCards(array $cardIds): array
    {
        if ([] === $cardIds) {
            return [];
        }

        /** @var list<array{card_id: string, kind: string, created_at: string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            "SELECT subject_id AS card_id, kind, created_at FROM work_requests
             WHERE subject_type = :subject AND subject_id IN (:cards) AND state IN ('open', 'claimed')
             ORDER BY created_at, id",
            ['subject' => WorkSubject::CARD, 'cards' => $cardIds],
            ['cards' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        return $rows;
    }

    /**
     * The ids of the subjects of a type that have an open or claimed request of the kind, in one query.
     *
     * @param list<string> $subjectIds RFC 4122 ids
     *
     * @return array<string, true> keyed by the subject id
     */
    public function findSubjectIdsWithLiveRequest(string $subjectType, string $kind, array $subjectIds): array
    {
        if ([] === $subjectIds) {
            return [];
        }

        /** @var list<string> $found */
        $found = $this->getEntityManager()->getConnection()->executeQuery(
            "SELECT DISTINCT subject_id FROM work_requests
             WHERE subject_type = :type AND kind = :kind AND subject_id IN (:subjects) AND state IN ('open', 'claimed')",
            ['type' => $subjectType, 'kind' => $kind, 'subjects' => $subjectIds],
            ['subjects' => ArrayParameterType::STRING],
        )->fetchFirstColumn();

        return array_fill_keys($found, true);
    }

    /**
     * The ids of the cards that have an open work request in the projects.
     *
     * @param list<string> $projectIds RFC 4122 ids
     *
     * @return list<string> RFC 4122 ids
     */
    public function findCardIdsWithOpenRequestIn(array $projectIds): array
    {
        if ([] === $projectIds) {
            return [];
        }

        /** @var list<string> $ids */
        $ids = $this->getEntityManager()->getConnection()->executeQuery(
            "SELECT DISTINCT subject_id FROM work_requests
             WHERE subject_type = :subject AND project_id IN (:projects) AND state = 'open'",
            ['subject' => WorkSubject::CARD, 'projects' => $projectIds],
            ['projects' => ArrayParameterType::STRING],
        )->fetchFirstColumn();

        return $ids;
    }

    /**
     * The open requests about a card of the project, oldest first.
     *
     * @return list<WorkRequest>
     */
    public function findOpenOfCardsInProject(Project $project): array
    {
        return array_values($this->createQueryBuilder('w')
            ->andWhere('w.project = :project')
            ->andWhere('w.subjectType = :subject')
            ->andWhere('w.state = :open')
            ->setParameter('project', $project)
            ->setParameter('subject', WorkSubject::CARD)
            ->setParameter('open', WorkRequestState::Open->value)
            ->orderBy('w.createdAt', 'ASC')
            ->addOrderBy('w.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /** @return list<string> RFC 4122 ids of the projects that have an open request about a card */
    public function findProjectIdsWithOpenCardRequest(): array
    {
        /** @var list<string> $ids */
        $ids = $this->getEntityManager()->getConnection()->executeQuery(
            "SELECT DISTINCT project_id FROM work_requests WHERE subject_type = :subject AND state = 'open'",
            ['subject' => WorkSubject::CARD],
        )->fetchFirstColumn();

        return $ids;
    }

    /** The newest request of the card that the rule opened for the kind, in any state. */
    public function findLatestOfCardKindRule(Uuid $cardId, string $kind, string $ruleId): ?WorkRequest
    {
        return $this->ofSubject(WorkSubject::card($cardId))
            ->andWhere('w.kind = :kind')
            ->andWhere('w.ruleId = :ruleId')
            ->setParameter('kind', $kind)
            ->setParameter('ruleId', $ruleId)
            ->orderBy('w.createdAt', 'DESC')
            ->addOrderBy('w.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The kind, the pull request number and the head of each request of the card that ended done with one of the heads.
     *
     * @param list<string> $headShas lower-case head shas
     *
     * @return list<array{kind: string, pullRequestNumber: int, headSha: string}>
     */
    public function findDoneAtHeads(Uuid $cardId, array $headShas): array
    {
        if ([] === $headShas) {
            return [];
        }

        /** @var list<array{kind: string, number: string|int|null, head: string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            "SELECT DISTINCT kind, (context::jsonb ->> 'pullRequestNumber') AS number, (context::jsonb ->> 'headSha') AS head FROM work_requests
             WHERE subject_type = :subject AND subject_id = :card AND state = :done AND (context::jsonb ->> 'headSha') IN (:heads)",
            ['subject' => WorkSubject::CARD, 'card' => $cardId->toRfc4122(), 'done' => WorkRequestState::Done->value, 'heads' => $headShas],
            ['heads' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $found = [];
        foreach ($rows as $row) {
            if (null !== $row['number']) {
                $found[] = ['kind' => $row['kind'], 'pullRequestNumber' => (int) $row['number'], 'headSha' => $row['head']];
            }
        }

        return $found;
    }

    /**
     * When the newest fix request for the agent review of each pull request of the card ended done.
     *
     * @return array<int, \DateTimeImmutable> keyed by pull request number
     */
    public function findAgentReviewFixesSettled(Uuid $cardId): array
    {
        /** @var list<array{number: string|int|null, settled: string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            "SELECT (context::jsonb ->> 'pullRequestNumber') AS number, MAX(settled_at) AS settled FROM work_requests
             WHERE subject_type = :subject AND subject_id = :card AND state = :done AND role = 'fix'
               AND (context::jsonb ->> 'reason') = 'agent-review' AND settled_at IS NOT NULL
             GROUP BY (context::jsonb ->> 'pullRequestNumber')",
            ['subject' => WorkSubject::CARD, 'card' => $cardId->toRfc4122(), 'done' => WorkRequestState::Done->value],
        )->fetchAllAssociative();

        $settled = [];
        foreach ($rows as $row) {
            if (null !== $row['number']) {
                $settled[(int) $row['number']] = new \DateTimeImmutable($row['settled']);
            }
        }

        return $settled;
    }

    /**
     * When the newest finished fix request opened for each pull request linked to each card. A request is finished when it
     * settled done, or when any worker run of it ended with success, so a resumed run counts after its request settled refused.
     * A request matches a pull request through the URL in its context and the links of the card, so two pull requests with
     * one number in two repositories stay apart.
     *
     * @param list<Uuid> $cardIds
     *
     * @return array<string, list<array{forge: string, repository: string, number: int, openedAt: \DateTimeImmutable}>> by RFC 4122 card id
     */
    public function findFinishedFixesOpenedAt(array $cardIds): array
    {
        if ([] === $cardIds) {
            return [];
        }

        /** @var list<array{card_id: string, forge: string, repository: string, number: string|int, opened: string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            "SELECT w.subject_id AS card_id, l.forge AS forge, LOWER(l.repository) AS repository, l.number AS number, MAX(w.created_at) AS opened
             FROM work_requests w
             JOIN board_card_pull_requests l ON l.card_id = w.subject_id AND l.url = (w.context::jsonb ->> 'pullRequestUrl')
             WHERE w.subject_type = :subject AND w.subject_id IN (:cards) AND w.role = 'fix'
               AND (w.state = :done OR EXISTS (SELECT 1 FROM bridge_worker_runs r WHERE r.work_request_id = w.id AND r.state = :succeeded))
               AND l.repository IS NOT NULL AND l.number IS NOT NULL
             GROUP BY w.subject_id, l.forge, LOWER(l.repository), l.number",
            [
                'subject' => WorkSubject::CARD,
                'cards' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $cardIds),
                'done' => WorkRequestState::Done->value,
                'succeeded' => WorkerRunState::Succeeded->value,
            ],
            ['cards' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $found = [];
        foreach ($rows as $row) {
            $found[Uuid::fromString($row['card_id'])->toRfc4122()][] = [
                'forge' => $row['forge'],
                'repository' => $row['repository'],
                'number' => (int) $row['number'],
                'openedAt' => new \DateTimeImmutable($row['opened']),
            ];
        }

        return $found;
    }

    /**
     * How many requests each card opened, in any state, by role, kind and the reason of its context. A request with no reason reads as
     * `unknown`. A card with no request has no row.
     *
     * @param list<Uuid> $cardIds
     *
     * @return list<array{cardId: string, role: ?string, kind: string, reason: string, count: int}>
     */
    public function findCountsOfCards(array $cardIds): array
    {
        if ([] === $cardIds) {
            return [];
        }

        /** @var list<array{card_id: string, role: ?string, kind: string, reason: string, total: string|int}> $rows */
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            "SELECT subject_id AS card_id, role, kind, COALESCE(context::jsonb ->> 'reason', 'unknown') AS reason, COUNT(*) AS total
             FROM work_requests
             WHERE subject_type = :subject AND subject_id IN (:cards)
             GROUP BY subject_id, role, kind, COALESCE(context::jsonb ->> 'reason', 'unknown')
             ORDER BY subject_id, role, kind, reason",
            ['subject' => WorkSubject::CARD, 'cards' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $cardIds)],
            ['cards' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'cardId' => Uuid::fromString($row['card_id'])->toRfc4122(),
            'role' => $row['role'],
            'kind' => $row['kind'],
            'reason' => $row['reason'],
            'count' => (int) $row['total'],
        ], $rows);
    }

    /** The request of the card that opened last, in any state. */
    public function findLatestForCard(Uuid $cardId): ?WorkRequest
    {
        return $this->ofSubject(WorkSubject::card($cardId))
            ->orderBy('w.createdAt', 'DESC')
            ->addOrderBy('w.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** The request of the card that settled done or refused last. */
    public function findLatestSettledForCard(Uuid $cardId): ?WorkRequest
    {
        return $this->ofSubject(WorkSubject::card($cardId))
            ->andWhere('w.state IN (:settled)')
            ->setParameter('settled', [WorkRequestState::Done->value, WorkRequestState::Refused->value])
            ->orderBy('w.settledAt', 'DESC')
            ->addOrderBy('w.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Streamed, because no sweep deletes old requests.
     *
     * @return iterable<WorkRequest>
     */
    public function findByOwner(User $user): iterable
    {
        return $this->createQueryBuilder('w')
            ->addSelect('p')
            ->join('w.project', 'p')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('w.createdAt', 'ASC')
            ->addOrderBy('w.id', 'ASC')
            ->getQuery()
            ->toIterable();
    }

    private function ofSubject(WorkSubject $subject): QueryBuilder
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.subjectType = :subjectType')
            ->andWhere('w.subjectId = :subjectId')
            ->setParameter('subjectType', $subject->type)
            ->setParameter('subjectId', $subject->id, UuidType::NAME);
    }
}
