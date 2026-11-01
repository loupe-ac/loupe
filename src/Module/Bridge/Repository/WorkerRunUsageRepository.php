<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunUsage;
use App\Module\Bridge\ValueObject\BilledCostState;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<WorkerRunUsage>
 */
class WorkerRunUsageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkerRunUsage::class);
    }

    /** A DQL delete runs at once, so new rows for the same models can follow in one flush. */
    public function deleteForRun(WorkerRun $run): void
    {
        $this->createQueryBuilder('u')
            ->delete()
            ->andWhere('u.run = :run')
            ->setParameter('run', $run)
            ->getQuery()
            ->execute();
    }

    /**
     * @param list<Uuid> $runIds
     *
     * @return array<string, list<WorkerRunUsage>> run id => usage rows, by model
     */
    public function findByRunIds(array $runIds): array
    {
        if ([] === $runIds) {
            return [];
        }

        /** @var list<array{0: WorkerRunUsage, runId: string}> $rows */
        $rows = $this->createQueryBuilder('u')
            ->select('u', 'IDENTITY(u.run) AS runId')
            ->andWhere('u.run IN (:runs)')
            ->setParameter('runs', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $runIds))
            ->orderBy('u.model', 'ASC')
            ->getQuery()
            ->getResult();

        $byRun = [];
        foreach ($rows as $row) {
            $byRun[$row['runId']][] = $row[0];
        }

        return $byRun;
    }

    /**
     * The sums of every usage row of a card, by project and card rather than by
     * run, so the rows of a deleted run still count. The billed costs come from
     * the billed cost rows by project and subject, so they survive the sweep too.
     * A usage row of a billed run adds tokens and no cost. `rows` counts the
     * usage rows and the billed cost rows.
     *
     * @return array{rows: int, cost: ?string, input: int, output: int, cacheRead: int, cacheWrite: int, estimated: bool, pendingRuns: int, noCostRuns: int}
     */
    public function sumForCard(Project $project, Uuid $cardId): array
    {
        /** @var array{row_count: int|string, cost: string|null, input: int|string|null, output: int|string|null, cache_read: int|string|null, cache_write: int|string|null, estimated: bool|null, pending_runs: int|string, no_cost_runs: int|string} $row */
        $row = $this->getEntityManager()->getConnection()->executeQuery(
            <<<'SQL'
                SELECT
                    u.row_count + b.row_count AS row_count,
                    CASE WHEN u.cost IS NULL AND b.cost IS NULL THEN NULL ELSE COALESCE(u.cost, 0) + COALESCE(b.cost, 0) END AS cost,
                    u.input,
                    u.output,
                    u.cache_read,
                    u.cache_write,
                    u.estimated,
                    b.pending_runs,
                    b.no_cost_runs
                FROM (
                    SELECT
                        COUNT(*) AS row_count,
                        SUM(cost_usd) FILTER (WHERE NOT billed_cost) AS cost,
                        SUM(input_tokens) AS input,
                        SUM(output_tokens) AS output,
                        SUM(cache_read_tokens) AS cache_read,
                        SUM(cache_write_tokens) AS cache_write,
                        BOOL_OR(source = :estimated OR (cost_usd IS NULL AND NOT billed_cost)) AS estimated
                    FROM bridge_worker_run_usage
                    WHERE project_id = :project AND subject_type = 'card' AND subject_id = :card
                ) u
                CROSS JOIN (
                    SELECT
                        COUNT(*) AS row_count,
                        SUM(cost_usd) AS cost,
                        COUNT(*) FILTER (WHERE state = :pending) AS pending_runs,
                        COUNT(*) FILTER (WHERE state = :unknown) AS no_cost_runs
                    FROM bridge_worker_run_billed_costs
                    WHERE project_id = :project AND subject_type = 'card' AND subject_id = :card
                ) b
                SQL,
            [
                'project' => (string) ($project->id ?? throw new \LogicException('Project has no id.')),
                'card' => (string) $cardId,
                'estimated' => WorkerRunUsageSource::Estimated->value,
                'pending' => BilledCostState::Pending->value,
                'unknown' => BilledCostState::Unknown->value,
            ],
        )->fetchAssociative();

        return [
            'rows' => (int) $row['row_count'],
            'cost' => $row['cost'],
            'input' => (int) $row['input'],
            'output' => (int) $row['output'],
            'cacheRead' => (int) $row['cache_read'],
            'cacheWrite' => (int) $row['cache_write'],
            'estimated' => true === $row['estimated'],
            'pendingRuns' => (int) $row['pending_runs'],
            'noCostRuns' => (int) $row['no_cost_runs'],
        ];
    }

    /**
     * Every usage row on every project the user owns, for the account data
     * export. It includes the rows whose run the retention sweep deleted.
     *
     * @return list<WorkerRunUsage>
     */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('u')
            ->join('u.project', 'p')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('u.id', 'ASC')
            ->getQuery()
            ->getResult());
    }
}
