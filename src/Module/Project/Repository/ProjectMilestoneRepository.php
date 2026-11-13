<?php

declare(strict_types=1);

namespace App\Module\Project\Repository;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\ProjectMilestone;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProjectMilestone>
 */
class ProjectMilestoneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectMilestone::class);
    }

    /** @return list<ProjectMilestone> every milestone of the projects the user owns, oldest first */
    public function findOfOwner(User $owner): array
    {
        return array_values($this->createQueryBuilder('m')
            ->join('m.project', 'p')
            ->andWhere('p.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('m.reachedAt', 'ASC')
            ->addOrderBy('m.key', 'ASC')
            ->getQuery()
            ->getResult());
    }
}
