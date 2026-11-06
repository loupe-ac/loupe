<?php

declare(strict_types=1);

namespace App\Module\Insights\Repository;

use App\Module\Account\Entity\User;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Entity\PublicLinkKind;
use App\Module\Insights\Entity\PublicLinkTakedown;
use App\Module\Insights\Service\PublicLinkCode;
use App\Module\Insights\View\PublicLinkProjectRow;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PublicLink> */
class PublicLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PublicLink::class);
    }

    public function findByCode(string $code): ?PublicLink
    {
        return $this->findOneBy(['code' => $code]);
    }

    public function findForGraph(InsightsSavedGraph $savedGraph): ?PublicLink
    {
        return $this->findOneBy(['savedGraph' => $savedGraph]);
    }

    /** @return array<string, PublicLink> the graph links of a project, by graph id */
    public function findGraphLinks(Project $project): array
    {
        $links = [];
        foreach ($this->findBy(['project' => $project, 'kind' => PublicLinkKind::Graph]) as $link) {
            $links[(string) $link->savedGraph?->id] = $link;
        }

        return $links;
    }

    /** @return list<PublicLink> the links of the projects the user owns, by project, then in order of creation */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('l')
            ->join('l.project', 'p')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->addOrderBy('l.createdAt', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    public function findForExperiment(Project $project, string $experiment): ?PublicLink
    {
        return $this->findOneBy(['project' => $project, 'experiment' => $experiment]);
    }

    /**
     * One row for each project that has a link, the taken down projects first. A search that names a code
     * narrows the row to that link.
     *
     * @return list<PublicLinkProjectRow>
     */
    public function findProjectRows(int $page, int $perPage, string $sort, string $dir, ?string $query, ?string $userId): array
    {
        $qb = $this->projectRowsQuery($query, $userId)
            ->select(
                'p.id AS projectId',
                'p.name AS projectName',
                'o.id AS ownerId',
                'o.fullName AS ownerName',
                'o.email AS ownerEmail',
                'COUNT(l.id) AS items',
                'SUM(CASE WHEN l.enabled = true AND (g.id IS NULL OR g.removedAt IS NULL) THEN 1 ELSE 0 END) AS liveItems',
                'MIN(l.code) AS code',
                't.takenDownAt AS takenDownAt',
                'a.email AS takenDownByEmail',
            )
            ->addSelect('CASE WHEN t.id IS NULL THEN 1 ELSE 0 END AS HIDDEN notTakenDown')
            ->groupBy('p.id, p.name, o.id, o.fullName, o.email, t.id, t.takenDownAt, a.id, a.email')
            ->orderBy('notTakenDown', 'ASC')
            ->addOrderBy(match ($sort) {
                'owner' => 'o.email',
                'items' => 'items',
                default => 'p.name',
            }, 'asc' === $dir ? 'ASC' : 'DESC')
            ->addOrderBy('p.id', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        $rows = [];
        /** @var list<array{projectId: \Symfony\Component\Uid\Uuid, projectName: string, ownerId: \Symfony\Component\Uid\Uuid, ownerName: string, ownerEmail: string, items: int|string, liveItems: int|string|null, code: string, takenDownAt: ?\DateTimeImmutable, takenDownByEmail: ?string}> $result */
        $result = $qb->getQuery()->getArrayResult();
        foreach ($result as $row) {
            $rows[] = new PublicLinkProjectRow(
                (string) $row['projectId'],
                $row['projectName'],
                (string) $row['ownerId'],
                $row['ownerName'],
                $row['ownerEmail'],
                (int) $row['items'],
                (int) $row['liveItems'],
                $row['code'],
                $row['takenDownAt'],
                $row['takenDownByEmail'],
            );
        }

        return $rows;
    }

    public function countProjects(?string $query, ?string $userId): int
    {
        return (int) $this->projectRowsQuery($query, $userId)
            ->select('COUNT(DISTINCT p.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countForProject(Project $project): int
    {
        return $this->count(['project' => $project]);
    }

    private function projectRowsQuery(?string $query, ?string $userId): QueryBuilder
    {
        $qb = $this->createQueryBuilder('l')
            ->join('l.project', 'p')
            ->join('p.owner', 'o')
            ->leftJoin('l.savedGraph', 'g')
            ->leftJoin(PublicLinkTakedown::class, 't', 'WITH', 't.project = p')
            ->leftJoin('t.takenDownBy', 'a');

        if (null !== $userId) {
            $qb->andWhere('o.id = :user')->setParameter('user', $userId);
        }
        $query = mb_strtolower(trim($query ?? ''));
        if ('' !== $query) {
            $qb->andWhere('(l.code = :code OR LOWER(p.name) LIKE :like OR LOWER(o.email) LIKE :like OR LOWER(o.fullName) LIKE :like)')
                ->setParameter('code', 1 === preg_match('/^'.PublicLinkCode::PATTERN.'$/', $query) ? $query : '')
                ->setParameter('like', '%'.addcslashes($query, '%_\\').'%');
        }

        return $qb;
    }
}
