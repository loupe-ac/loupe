<?php

declare(strict_types=1);

namespace App\Module\Inbox\Repository;

use App\Doctrine\SearchLanguage;
use App\Module\Account\Entity\User;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<InboxAsk>
 */
class InboxAskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InboxAsk::class);
    }

    /** The session's open ask in any project. At most one exists. */
    public function findOpenForSession(Uuid $sessionId): ?InboxAsk
    {
        /* @var ?InboxAsk */
        return $this->createQueryBuilder('a')
            ->andWhere('a.sessionId = :sessionId')
            ->andWhere('a.closedAt IS NULL')
            ->setParameter('sessionId', $sessionId, UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * When each ask closed, or null for an open one. An ask that does not exist has no entry.
     *
     * @param list<string> $askIds RFC 4122 strings
     *
     * @return array<string, ?\DateTimeImmutable> keyed by the RFC 4122 ask id
     */
    public function findClosedAtByIds(array $askIds): array
    {
        if ([] === $askIds) {
            return [];
        }

        /** @var list<array{id: Uuid, closedAt: ?\DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.id', 'a.closedAt')
            ->andWhere('a.id IN (:ids)')
            ->setParameter('ids', $askIds)
            ->getQuery()
            ->getArrayResult();

        $closed = [];
        foreach ($rows as $row) {
            $closed[$row['id']->toRfc4122()] = $row['closedAt'];
        }

        return $closed;
    }

    /** When the newest ask of the session on the bridge closed after the time, counting only an ask that held a blocking item. */
    public function findLatestBlockingCloseOfSessionAfter(Uuid $projectId, Uuid $sessionId, Uuid $bridgeId, \DateTimeImmutable $after): ?\DateTimeImmutable
    {
        /** @var list<array{closedAt: \DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.closedAt')
            ->andWhere('a.project = :projectId')
            ->andWhere('a.sessionId = :sessionId')
            ->andWhere('a.bridgeId = :bridgeId')
            ->andWhere('a.closedAt > :after')
            ->andWhere(\sprintf('EXISTS (SELECT 1 FROM %s l JOIN l.item i WHERE l.ask = a AND i.blocking = true)', InboxAskItem::class))
            ->setParameter('projectId', $projectId, UuidType::NAME)
            ->setParameter('sessionId', $sessionId, UuidType::NAME)
            ->setParameter('bridgeId', $bridgeId, UuidType::NAME)
            ->setParameter('after', $after, Types::DATETIME_IMMUTABLE)
            ->orderBy('a.closedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getArrayResult();

        return $rows[0]['closedAt'] ?? null;
    }

    /** The ask with its item memberships, or null when the project holds no such ask. */
    public function findOneByIdAndProject(Uuid $id, Project $project): ?InboxAsk
    {
        /* @var ?InboxAsk */
        return $this->createQueryBuilder('a')
            ->leftJoin('a.items', 'l')
            ->addSelect('l')
            ->andWhere('a.id = :id')
            ->andWhere('a.project = :project')
            ->setParameter('id', $id, UuidType::NAME)
            ->setParameter('project', $project)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Every ask that holds the item, oldest first.
     *
     * @return list<InboxAsk>
     */
    public function findHolding(InboxItem $item): array
    {
        /* @var list<InboxAsk> */
        return $this->createQueryBuilder('a')
            ->join('a.items', 'l')
            ->andWhere('l.item = :item')
            ->setParameter('item', $item)
            ->orderBy('a.createdAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The asks that hold the item and are open as stored, oldest first.
     *
     * @return list<InboxAsk>
     */
    public function findOpenHolding(InboxItem $item): array
    {
        return array_values($this->createQueryBuilder('a')
            ->join('a.items', 'l')
            ->andWhere('l.item = :item')
            ->andWhere('a.closedAt IS NULL')
            ->setParameter('item', $item)
            ->orderBy('a.createdAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * Every ask in the projects the user owns, with its item memberships.
     *
     * @return list<InboxAsk>
     */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('a')
            ->join('a.project', 'p')
            ->leftJoin('a.items', 'l')
            ->addSelect('l')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('a.createdAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * The open asks of a project, oldest first, with their items.
     *
     * @return list<InboxAsk>
     */
    public function findOpenForProject(Project $project): array
    {
        return array_values($this->withItems()
            ->andWhere('a.project = :project')
            ->andWhere('a.closedAt IS NULL')
            ->setParameter('project', $project)
            ->orderBy('a.createdAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->addOrderBy('l.addedAt', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * The open asks in every project the user owns, oldest first, with their projects and items.
     *
     * @return list<InboxAsk>
     */
    public function findOpenByOwner(User $user): array
    {
        return array_values($this->withItems()
            ->join('a.project', 'p')
            ->addSelect('p')
            ->andWhere('p.owner = :user')
            ->andWhere('a.closedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('a.createdAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->addOrderBy('l.addedAt', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /** @param ?InboxItemKind $kind counts only the asks that hold an item of the kind */
    public function countClosedForProject(Project $project, ?InboxItemKind $kind = null): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.project = :project')
            ->andWhere('a.closedAt IS NOT NULL')
            ->setParameter('project', $project);

        return (int) $this->holdingKind($qb, $kind)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * One page of the closed asks of a project, newest close first, with their items.
     *
     * The page is cut on ids first: a limit on the fetch-joined query would
     * count item rows, not asks.
     *
     * @param ?InboxItemKind $kind keeps only the asks that hold an item of the kind
     *
     * @return list<InboxAsk>
     */
    public function findClosedPageForProject(Project $project, int $offset, int $limit, ?InboxItemKind $kind = null): array
    {
        $idQuery = $this->createQueryBuilder('a')
            ->select('a.id')
            ->andWhere('a.project = :project')
            ->andWhere('a.closedAt IS NOT NULL')
            ->setParameter('project', $project);
        $ids = array_column($this->holdingKind($idQuery, $kind)
            ->orderBy('a.closedAt', 'DESC')
            ->addOrderBy('a.id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult(), 'id');

        if ([] === $ids) {
            return [];
        }

        return array_values($this->withItems()
            ->andWhere('a.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('a.closedAt', 'DESC')
            ->addOrderBy('a.id', 'ASC')
            ->addOrderBy('l.addedAt', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * The ids of the given items that a closed ask holds, which makes an answer on them final.
     *
     * @param list<InboxItem> $items
     *
     * @return list<string>
     */
    public function findItemIdsHeldByClosedAsks(array $items): array
    {
        if ([] === $items) {
            return [];
        }

        /** @var list<array{id: mixed}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT IDENTITY(l.item) AS id')
            ->from(InboxAskItem::class, 'l')
            ->join('l.ask', 'a')
            ->andWhere('l.item IN (:items)')
            ->andWhere('a.closedAt IS NOT NULL')
            ->setParameter('items', $items)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => (string) $row['id'], $rows);
    }

    /**
     * Stamps the read on each given item that a closed ask of the session holds.
     * An earlier stamp stays, so the first read after the close is the one kept.
     *
     * @param list<InboxItem> $items
     */
    public function recordRead(Uuid $sessionId, array $items, \DateTimeImmutable $now): void
    {
        if ([] === $items) {
            return;
        }

        $this->getEntityManager()->createQueryBuilder()
            ->update(InboxAskItem::class, 'l')
            ->set('l.readAt', ':now')
            ->andWhere('l.readAt IS NULL')
            ->andWhere('l.item IN (:items)')
            ->andWhere(\sprintf('l.ask IN (SELECT a.id FROM %s a WHERE a.sessionId = :sessionId AND a.closedAt IS NOT NULL)', InboxAsk::class))
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setParameter('items', $items)
            ->setParameter('sessionId', $sessionId, UuidType::NAME)
            ->getQuery()
            ->execute();
    }

    /**
     * One page of the asks that hold an item matching a full-text query, and of
     * the matching items that stand alone, best match first. An item stands
     * alone when it is open and no open ask holds it, or when no ask holds it.
     * An ask ranks as its best item. The kind keeps an ask when any of its
     * items has it.
     *
     * One query groups the hits by ask, counts them, clamps the page and cuts
     * it, so a page counts asks and items rather than item rows.
     *
     * @param list<SearchLanguage> $languages the languages the project's items are indexed with
     */
    public function searchRows(Project $project, string $query, ?InboxItemKind $kind, array $languages, int $page, int $perPage): InboxSearchPage
    {
        $entityManager = $this->getEntityManager();
        $parameters = [
            'project' => (string) ($project->id ?? throw new \LogicException('Project has no id.')),
            'open' => InboxItemState::Open->value,
            'page' => $page,
            'perPage' => $perPage,
        ];
        $types = ['page' => ParameterType::INTEGER, 'perPage' => ParameterType::INTEGER];
        $match = InboxSearchPredicate::matchSql('i', $languages, $query, $parameters);
        $rank = InboxSearchPredicate::rankSql('i');
        $askKind = '';
        $itemKind = '';
        if (null !== $kind) {
            $parameters['kind'] = $kind->value;
            $askKind = 'AND EXISTS (SELECT 1 FROM inbox_ask_items lk JOIN inbox_items ik ON ik.id = lk.item_id WHERE lk.ask_id = a.id AND ik.kind = :kind)';
            $itemKind = 'AND i.kind = :kind';
        }

        /** @var non-empty-list<array{total: int|string, page: int|string, kind: ?string, id: ?string}> $hits */
        $hits = $entityManager->getConnection()->executeQuery(
            "WITH hits AS (
                SELECT 'ask' AS kind, a.id, MAX($rank) AS rank, MAX(i.number) AS number
                FROM inbox_asks a
                JOIN inbox_ask_items l ON l.ask_id = a.id
                JOIN inbox_items i ON i.id = l.item_id
                WHERE a.project_id = :project AND $match $askKind
                GROUP BY a.id
                UNION ALL
                SELECT 'item', i.id, $rank, i.number
                FROM inbox_items i
                WHERE i.project_id = :project AND $match $itemKind
                  AND ((i.state = :open AND NOT EXISTS (SELECT 1 FROM inbox_ask_items lo JOIN inbox_asks ao ON ao.id = lo.ask_id WHERE lo.item_id = i.id AND ao.closed_at IS NULL))
                    OR NOT EXISTS (SELECT 1 FROM inbox_ask_items la WHERE la.item_id = i.id))
            ),
            paging AS (
                SELECT n, CAST(LEAST(GREATEST(:page, 1), GREATEST(1, CEIL(n / CAST(:perPage AS numeric)))) AS int) AS p
                FROM (SELECT COUNT(*) AS n FROM hits) total
            )
            SELECT paging.n AS total, paging.p AS page, h.kind, h.id
            FROM paging
            LEFT JOIN LATERAL (
                SELECT kind, id, rank, number FROM hits
                ORDER BY rank DESC, number DESC, id ASC
                LIMIT :perPage OFFSET (paging.p - 1) * :perPage
            ) h ON true
            ORDER BY h.rank DESC, h.number DESC, h.id ASC",
            $parameters,
            $types,
        )->fetchAllAssociative();

        $total = (int) $hits[0]['total'];
        $page = (int) $hits[0]['page'];
        $pageHits = array_values(array_map(
            static fn (array $hit): array => ['ask' => 'ask' === $hit['kind'], 'id' => (string) $hit['id']],
            array_filter($hits, static fn (array $hit): bool => null !== $hit['id']),
        ));

        $askIds = array_column(array_filter($pageHits, static fn (array $hit): bool => $hit['ask']), 'id');
        $itemIds = array_column(array_filter($pageHits, static fn (array $hit): bool => !$hit['ask']), 'id');
        $loaded = [];
        if ([] !== $askIds) {
            foreach ($this->withItems()->andWhere('a.id IN (:ids)')->setParameter('ids', $askIds)->getQuery()->getResult() as $ask) {
                $loaded['a'.$ask->id] = $ask;
            }
        }
        if ([] !== $itemIds) {
            foreach ($entityManager->createQueryBuilder()->select('i')->from(InboxItem::class, 'i')->andWhere('i.id IN (:ids)')->setParameter('ids', $itemIds)->getQuery()->getResult() as $item) {
                $loaded['i'.$item->id] = $item;
            }
        }

        $rows = [];
        foreach ($pageHits as $hit) {
            $row = $loaded[($hit['ask'] ? 'a' : 'i').$hit['id']] ?? null;
            if (null !== $row) {
                $rows[] = $row;
            }
        }

        return new InboxSearchPage($rows, $total, $page);
    }

    /** Keeps the asks of the query that hold an item of the kind, when there is one. */
    private function holdingKind(QueryBuilder $qb, ?InboxItemKind $kind): QueryBuilder
    {
        if (null === $kind) {
            return $qb;
        }

        return $qb
            ->andWhere(\sprintf('EXISTS (SELECT 1 FROM %s lk JOIN lk.item ik WHERE lk.ask = a AND ik.kind = :kind)', InboxAskItem::class))
            ->setParameter('kind', $kind);
    }

    private function withItems(): QueryBuilder
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.items', 'l')
            ->addSelect('l')
            ->leftJoin('l.item', 'i')
            ->addSelect('i');
    }
}
