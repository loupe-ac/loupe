<?php

declare(strict_types=1);

namespace App\Module\Insights\Repository;

use App\Module\Account\Entity\User;
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

    /** @return array<string, PublicLink> the experiment links of a project, by experiment name */
    public function findExperimentLinks(Project $project): array
    {
        $links = [];
        foreach ($this->findBy(['project' => $project, 'kind' => PublicLinkKind::Experiment]) as $link) {
            $links[(string) $link->experiment] = $link;
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
}
