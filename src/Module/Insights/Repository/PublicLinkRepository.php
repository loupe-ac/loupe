<?php

declare(strict_types=1);

namespace App\Module\Insights\Repository;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Entity\PublicLinkKind;
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

    /** @return array<string, PublicLink> the graph links of a project, by graph id */
    public function findGraphLinks(Project $project): array
    {
        $links = [];
        foreach ($this->findBy(['project' => $project, 'kind' => PublicLinkKind::Graph]) as $link) {
            $links[(string) $link->savedGraph?->id] = $link;
        }

        return $links;
    }

    public function findForExperiment(Project $project, string $experiment): ?PublicLink
    {
        return $this->findOneBy(['project' => $project, 'experiment' => $experiment]);
    }
}
