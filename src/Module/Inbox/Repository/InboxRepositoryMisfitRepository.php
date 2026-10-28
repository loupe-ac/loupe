<?php

declare(strict_types=1);

namespace App\Module\Inbox\Repository;

use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxRepositoryMisfit;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InboxRepositoryMisfit> */
class InboxRepositoryMisfitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InboxRepositoryMisfit::class);
    }

    public function findOpenForProject(Project $project): ?InboxRepositoryMisfit
    {
        return $this->createQueryBuilder('misfit')
            ->addSelect('item')
            ->join('misfit.item', 'item')
            ->andWhere('misfit.project = :project')
            ->andWhere('misfit.endedAt IS NULL')
            ->setParameter('project', $project)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneForItem(InboxItem $item): ?InboxRepositoryMisfit
    {
        return $this->findOneBy(['item' => $item]);
    }
}
