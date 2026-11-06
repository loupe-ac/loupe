<?php

declare(strict_types=1);

namespace App\Module\Insights\Repository;

use App\Module\Insights\Entity\PublicLinkTakedown;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PublicLinkTakedown> */
class PublicLinkTakedownRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PublicLinkTakedown::class);
    }

    public function findForProject(Project $project): ?PublicLinkTakedown
    {
        return $this->findOneBy(['project' => $project]);
    }

    public function existsForProject(Project $project): bool
    {
        return null !== $this->findForProject($project);
    }
}
