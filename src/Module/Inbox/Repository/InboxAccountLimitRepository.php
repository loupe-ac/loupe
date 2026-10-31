<?php

declare(strict_types=1);

namespace App\Module\Inbox\Repository;

use App\Module\Inbox\Entity\InboxAccountLimit;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InboxAccountLimit> */
class InboxAccountLimitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InboxAccountLimit::class);
    }

    /**
     * The open episodes of the project, with their items.
     *
     * @return array<string, InboxAccountLimit> by InboxAccountLimit::keyOf()
     */
    public function findOpenForProject(Project $project): array
    {
        /** @var list<InboxAccountLimit> $rows */
        $rows = $this->createQueryBuilder('episode')
            ->addSelect('item')
            ->join('episode.item', 'item')
            ->andWhere('episode.project = :project')
            ->andWhere('episode.endedAt IS NULL')
            ->setParameter('project', $project)
            ->getQuery()
            ->getResult();

        $byAccount = [];
        foreach ($rows as $row) {
            $byAccount[InboxAccountLimit::keyOf($row->bridgeId, $row->account)] = $row;
        }

        return $byAccount;
    }

    /** @return list<string> RFC 4122 ids */
    public function findProjectIdsWithOpenEpisode(): array
    {
        /** @var list<string> $ids */
        $ids = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT DISTINCT project_id FROM inbox_account_limits WHERE ended_at IS NULL',
        )->fetchFirstColumn();

        return $ids;
    }
}
