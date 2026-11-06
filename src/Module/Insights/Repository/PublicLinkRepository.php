<?php

declare(strict_types=1);

namespace App\Module\Insights\Repository;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

    public function findForExperiment(Project $project, string $experiment): ?PublicLink
    {
        return $this->findOneBy(['project' => $project, 'experiment' => $experiment]);
    }
}
