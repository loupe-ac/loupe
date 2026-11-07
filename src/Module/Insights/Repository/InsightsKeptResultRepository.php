<?php

declare(strict_types=1);

namespace App\Module\Insights\Repository;

use App\Module\Insights\Entity\InsightsKeptResult;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\View\KeptResultRow;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<InsightsKeptResult> */
class InsightsKeptResultRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InsightsKeptResult::class);
    }

    /**
     * Reads with the connection, because a write replaces a row under any entity that an earlier read made.
     *
     * @param list<InsightsSavedGraph> $graphs
     *
     * @return array<string, KeptResultRow> the rows by graph id, for the graphs that have one
     */
    public function findForGraphs(array $graphs): array
    {
        if ([] === $graphs) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT saved_graph_id, settings_hash, computed_at, payload FROM insights_kept_results WHERE saved_graph_id IN (:ids)',
            ['ids' => array_map(static fn (InsightsSavedGraph $graph): string => (string) $graph->id, $graphs)],
            ['ids' => ArrayParameterType::STRING],
        );

        $found = [];
        foreach ($rows as $row) {
            $found[(string) $row['saved_graph_id']] = $this->row($row);
        }

        return $found;
    }

    public function findForGraph(InsightsSavedGraph $graph): ?KeptResultRow
    {
        return $this->findForGraphs([$graph])[(string) $graph->id] ?? null;
    }

    public function findForExperiment(Project $project, string $experiment): ?KeptResultRow
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT settings_hash, computed_at, payload FROM insights_kept_results WHERE project_id = :project AND experiment = :experiment',
            ['project' => $project->requireId()->toRfc4122(), 'experiment' => $experiment],
        );

        return false === $row ? null : $this->row($row);
    }

    /** Replaces the row of a graph. The caller holds the compute lock of the graph, so the result is the newest one. */
    public function keepGraph(InsightsSavedGraph $graph, string $settingsHash, \DateTimeImmutable $computedAt, string $payload): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO insights_kept_results (id, project_id, saved_graph_id, experiment, settings_hash, computed_at, payload) VALUES (:id, :project, :graph, NULL, :hash, :computedAt, :payload) '
            .'ON CONFLICT (saved_graph_id) DO UPDATE SET settings_hash = EXCLUDED.settings_hash, computed_at = EXCLUDED.computed_at, payload = EXCLUDED.payload',
            ['id' => Uuid::v7()->toRfc4122(), 'project' => $graph->project->requireId()->toRfc4122(), 'graph' => $graph->id?->toRfc4122(), 'hash' => $settingsHash, 'computedAt' => $computedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'payload' => $payload],
        );
    }

    /** @return bool true when a row was removed */
    public function forgetGraph(InsightsSavedGraph $graph): bool
    {
        return 0 < $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM insights_kept_results WHERE saved_graph_id = :graph',
            ['graph' => $graph->id?->toRfc4122()],
        );
    }

    /** Replaces the row of a shared experiment. The caller holds the compute lock of the experiment. */
    public function keepExperiment(Project $project, string $experiment, \DateTimeImmutable $computedAt, string $payload): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO insights_kept_results (id, project_id, saved_graph_id, experiment, settings_hash, computed_at, payload) VALUES (:id, :project, NULL, :experiment, \'\', :computedAt, :payload) '
            .'ON CONFLICT (project_id, experiment) DO UPDATE SET computed_at = EXCLUDED.computed_at, payload = EXCLUDED.payload',
            ['id' => Uuid::v7()->toRfc4122(), 'project' => $project->requireId()->toRfc4122(), 'experiment' => $experiment, 'computedAt' => $computedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'payload' => $payload],
        );
    }

    /** The reading of the database clock, in UTC. The web and the worker containers share it. */
    public function databaseNow(): \DateTimeImmutable
    {
        $reading = $this->getEntityManager()->getConnection()->fetchOne("SELECT to_char(clock_timestamp() AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US')");

        return new \DateTimeImmutable(\is_string($reading) ? $reading : 'now', new \DateTimeZone('UTC'));
    }

    /** @param array<string, mixed> $row */
    private function row(array $row): KeptResultRow
    {
        return new KeptResultRow((string) $row['settings_hash'], new \DateTimeImmutable((string) $row['computed_at'], new \DateTimeZone('UTC')), (string) $row['payload']);
    }
}
