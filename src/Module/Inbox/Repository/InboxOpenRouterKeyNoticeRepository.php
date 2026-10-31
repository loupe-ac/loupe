<?php

declare(strict_types=1);

namespace App\Module\Inbox\Repository;

use App\Module\Inbox\Entity\InboxOpenRouterKeyNotice;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<InboxOpenRouterKeyNotice> */
class InboxOpenRouterKeyNoticeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InboxOpenRouterKeyNotice::class);
    }

    public function findOpenForKeyInProject(Uuid $keyId, Project $project): ?InboxOpenRouterKeyNotice
    {
        return $this->createQueryBuilder('notice')
            ->addSelect('item')
            ->join('notice.item', 'item')
            ->andWhere('notice.keyId = :key')
            ->andWhere('notice.project = :project')
            ->andWhere('notice.endedAt IS NULL')
            ->setParameter('key', $keyId, UuidType::NAME)
            ->setParameter('project', $project)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<InboxOpenRouterKeyNotice> */
    public function findOpenForKey(Uuid $keyId): array
    {
        /** @var list<InboxOpenRouterKeyNotice> $notices */
        $notices = $this->createQueryBuilder('notice')
            ->addSelect('item', 'project')
            ->join('notice.item', 'item')
            ->join('notice.project', 'project')
            ->andWhere('notice.keyId = :key')
            ->andWhere('notice.endedAt IS NULL')
            ->orderBy('notice.startedAt', 'ASC')
            ->addOrderBy('notice.id', 'ASC')
            ->setParameter('key', $keyId, UuidType::NAME)
            ->getQuery()
            ->getResult();

        return $notices;
    }
}
