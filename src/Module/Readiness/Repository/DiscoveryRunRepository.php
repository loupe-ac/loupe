<?php

declare(strict_types=1);

namespace App\Module\Readiness\Repository;

use App\Module\Project\Entity\Project;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Review\Entity\Document;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<DiscoveryRun> */
class DiscoveryRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DiscoveryRun::class);
    }

    public function latestForProject(Project $project): ?DiscoveryRun
    {
        return $this->createQueryBuilder('run')
            ->where('run.project = :project')
            ->setParameter('project', $project)
            ->orderBy('run.createdAt', 'DESC')
            ->addOrderBy('run.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The projects whose latest discovery run did not fail. The owner has no run to start for them.
     *
     * @param list<Uuid> $projectIds
     *
     * @return list<string> RFC 4122 project ids
     */
    public function findProjectIdsWithDiscoveryStarted(array $projectIds): array
    {
        if ([] === $projectIds) {
            return [];
        }
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT project_id FROM (
                 SELECT DISTINCT ON (project_id) project_id, state
                 FROM discovery_runs
                 WHERE project_id IN (:projectIds)
                 ORDER BY project_id, created_at DESC, id DESC
             ) latest
             WHERE state <> :failed',
            ['projectIds' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $projectIds), 'failed' => DiscoveryRunState::Failed->value],
            ['projectIds' => ArrayParameterType::STRING],
        );

        return array_map(static fn (mixed $id): string => Uuid::fromString((string) $id)->toRfc4122(), $ids);
    }

    public function latestForCard(Uuid $cardId): ?DiscoveryRun
    {
        return $this->createQueryBuilder('run')
            ->where('run.card = :card')
            ->setParameter('card', $cardId, UuidType::NAME)
            ->orderBy('run.createdAt', 'DESC')
            ->addOrderBy('run.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<DiscoveryRun> */
    public function findRequestedForProject(Project $project): array
    {
        return array_values($this->createQueryBuilder('run')
            ->where('run.project = :project')
            ->andWhere('run.state = :state')
            ->setParameter('project', $project)
            ->setParameter('state', DiscoveryRunState::Requested)
            ->orderBy('run.createdAt', 'ASC')
            ->addOrderBy('run.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    public function findByReportDocument(Document $document): ?DiscoveryRun
    {
        return $this->findOneBy(['reportDocument' => $document]);
    }

    /**
     * Reads the state and the report straight from the row, because the loaded run can be stale.
     * Doctrine cannot refresh a run, since its constructor properties are read-only.
     *
     * @return array{state: DiscoveryRunState, hasReport: bool}
     */
    public function freshStateOf(DiscoveryRun $run): array
    {
        $row = $this->createQueryBuilder('run')
            ->select('run.state AS state', 'IDENTITY(run.reportDocument) AS reportDocumentId')
            ->where('run.id = :id')
            ->setParameter('id', $run->id, UuidType::NAME)
            ->getQuery()
            ->getSingleResult();

        return ['state' => $row['state'], 'hasReport' => null !== $row['reportDocumentId']];
    }
}
