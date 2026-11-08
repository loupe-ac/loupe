<?php

declare(strict_types=1);

namespace App\Module\Insights\Repository;

use App\Module\Insights\Entity\InsightsProjectActivity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Reads and writes with the connection, because the upsert of a stamp replaces a row under any entity that an earlier
 * read made.
 *
 * @extends ServiceEntityRepository<InsightsProjectActivity>
 */
class InsightsProjectActivityRepository extends ServiceEntityRepository
{
    /** A stamp that is younger than this is not written again, so a busy project writes at most once an hour. */
    private const string STAMP_LIMIT = '-1 hour';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InsightsProjectActivity::class);
    }

    public function stampView(string $projectId, \DateTimeImmutable $now): void
    {
        $this->stamp('viewed_at', $projectId, $now);
    }

    public function stampData(string $projectId, \DateTimeImmutable $now): void
    {
        $this->stamp('data_at', $projectId, $now);
    }

    /** A project with no activity row counts as dormant. */
    public function isAwake(string $projectId, \DateTimeImmutable $now, int $dormantDays): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM insights_project_activity WHERE project_id = :project AND GREATEST(viewed_at, data_at) >= :cutoff',
            ['project' => $projectId, 'cutoff' => self::format($now->modify('-'.$dormantDays.' days'))],
        );
    }

    /**
     * The awake projects that hold a rolling-range graph, a template metric graph or a shared experiment whose kept
     * result is missing or older than the cutoff. A graph with a range of "All" and a built-in metric changes only
     * with new data, so the sweep leaves it to the data events.
     *
     * @param list<string> $builtInMetrics the keys of the built-in metrics
     *
     * @return list<string> the project ids
     */
    public function findToSweep(\DateTimeImmutable $now, int $dormantDays, int $intervalMinutes, array $builtInMetrics): array
    {
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            <<<'SQL'
                SELECT a.project_id
                FROM insights_project_activity a
                WHERE GREATEST(a.viewed_at, a.data_at) >= :awake
                AND (
                    EXISTS (
                        SELECT 1 FROM insights_saved_graphs g
                        LEFT JOIN insights_kept_results k ON k.saved_graph_id = g.id
                        WHERE g.project_id = a.project_id AND g.removed_at IS NULL
                        AND (g.range IN ('seven-days', 'thirty-days', 'ninety-days') OR split_part(g.metric, ':', 1) NOT IN (:builtIn))
                        AND (k.computed_at IS NULL OR k.computed_at < :stale)
                    )
                    OR (
                        NOT EXISTS (SELECT 1 FROM insights_public_link_takedowns t WHERE t.project_id = a.project_id)
                        AND EXISTS (
                            SELECT 1 FROM insights_public_links l
                            LEFT JOIN insights_kept_results k ON k.project_id = l.project_id AND k.experiment = l.experiment
                            WHERE l.project_id = a.project_id AND l.kind = 'experiment' AND l.enabled
                            AND (k.computed_at IS NULL OR k.computed_at < :stale)
                        )
                    )
                )
                SQL,
            [
                'awake' => self::format($now->modify('-'.$dormantDays.' days')),
                'stale' => self::format($now->modify('-'.$intervalMinutes.' minutes')),
                'builtIn' => $builtInMetrics,
            ],
            ['builtIn' => ArrayParameterType::STRING],
        );

        return array_map(strval(...), $ids);
    }

    /** @param 'viewed_at'|'data_at' $column */
    private function stamp(string $column, string $projectId, \DateTimeImmutable $now): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            \sprintf(
                'INSERT INTO insights_project_activity (project_id, %1$s) VALUES (:project, :now) '
                .'ON CONFLICT (project_id) DO UPDATE SET %1$s = EXCLUDED.%1$s '
                .'WHERE insights_project_activity.%1$s IS NULL OR insights_project_activity.%1$s < :limit',
                $column,
            ),
            ['project' => $projectId, 'now' => self::format($now), 'limit' => self::format($now->modify(self::STAMP_LIMIT))],
        );
    }

    private static function format(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
