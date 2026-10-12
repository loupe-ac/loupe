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

    /**
     * The page of the completed queue that holds a closed ask, counting from 1.
     *
     * The queue lists the closed asks newest close first, so the rank of an ask
     * is the number of asks that close after it, ties broken the way
     * findClosedPageForProject() breaks them.
     */
    public function closedPageOf(InboxAsk $ask, int $perPage): int
    {
        if (null === $ask->closedAt) {
            return 1;
        }

        $ahead = (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.project = :project')
            ->andWhere('a.closedAt IS NOT NULL')
            ->andWhere('a.closedAt > :closedAt OR (a.closedAt = :closedAt AND a.id < :id)')
            ->setParameter('project', $ask->project)
            ->setParameter('closedAt', $ask->closedAt)
            ->setParameter('id', $ask->id)
            ->getQuery()
            ->getSingleScalarResult();

        return intdiv($ahead, $perPage) + 1;
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
     * The ids and ranks of every hit are read first, so one page counts asks
     * and items rather than the item rows a join would return.
     *
     * @param list<SearchLanguage> $languages the languages the project's items are indexed with
     */
    public function searchRows(Project $project, string $query, ?InboxItemKind $kind, array $languages, int $page, int $perPage): InboxSearchPage
    {
        $entityManager = $this->getEntityManager();

        $asks = $entityManager->createQueryBuilder()
            ->select('IDENTITY(l.ask) AS id', 'MAX('.InboxSearchPredicate::rank('i').') AS rank', 'MAX(i.number) AS number')
            ->from(InboxAskItem::class, 'l')
            ->join('l.item', 'i')
            ->join('l.ask', 'a')
            ->andWhere('a.project = :project')
            ->groupBy('l.ask')
            ->setParameter('project', $project);
        $asks->andWhere(InboxSearchPredicate::match($asks, 'i', $languages, $query));
        $this->holdingKind($asks, $kind);

        $items = $entityManager->createQueryBuilder()
            ->select('i.id AS id', InboxSearchPredicate::rank('i').' AS rank', 'i.number AS number')
            ->from(InboxItem::class, 'i')
            ->andWhere('i.project = :project')
            ->andWhere(\sprintf('((i.state = :open AND NOT EXISTS (SELECT 1 FROM %1$s lo JOIN lo.ask ao WHERE lo.item = i AND ao.closedAt IS NULL)) OR NOT EXISTS (SELECT 1 FROM %1$s la WHERE la.item = i))', InboxAskItem::class))
            ->setParameter('project', $project)
            ->setParameter('open', InboxItemState::Open);
        $items->andWhere(InboxSearchPredicate::match($items, 'i', $languages, $query));
        if (null !== $kind) {
            $items->andWhere('i.kind = :kind')->setParameter('kind', $kind);
        }

        $hits = [];
        foreach ($asks->getQuery()->getArrayResult() as $row) {
            $hits[] = ['ask' => true, 'id' => (string) $row['id'], 'rank' => (float) $row['rank'], 'number' => (int) $row['number']];
        }
        foreach ($items->getQuery()->getArrayResult() as $row) {
            $hits[] = ['ask' => false, 'id' => (string) $row['id'], 'rank' => (float) $row['rank'], 'number' => (int) $row['number']];
        }
        usort($hits, static fn (array $a, array $b): int => [$b['rank'], $b['number'], $a['id']] <=> [$a['rank'], $a['number'], $b['id']]);

        $total = \count($hits);
        $page = min(max(1, $page), max(1, (int) ceil($total / $perPage)));
        $pageHits = \array_slice($hits, ($page - 1) * $perPage, $perPage);

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
