<?php

declare(strict_types=1);

namespace App\Module\Inbox\Repository;

use App\Module\Inbox\Entity\InboxBridgeOutage;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InboxBridgeOutage> */
class InboxBridgeOutageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InboxBridgeOutage::class);
    }

    public function findOpenForProject(Project $project): ?InboxBridgeOutage
    {
        return $this->createQueryBuilder('outage')
            ->addSelect('item')
            ->join('outage.item', 'item')
            ->andWhere('outage.project = :project')
            ->andWhere('outage.endedAt IS NULL')
            ->setParameter('project', $project)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<string> RFC 4122 ids */
    public function findProjectIdsWithOpenOutage(): array
    {
        /** @var list<string> $ids */
        $ids = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT project_id FROM inbox_bridge_outages WHERE ended_at IS NULL',
        )->fetchFirstColumn();

        return $ids;
    }
}
