<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Repository\WorkflowCardChangeRepository;
use App\Observability\SentryDsnStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sends the marked cards of this tick and the writes of each fact table to Sentry. With no usable DSN, it sends nothing.
 * A table count is every insert, update and delete of the table since the statistics began, so Sentry graphs its rate.
 */
final readonly class WorkflowSweepMetrics
{
    public function __construct(
        private WorkflowCardChangeRepository $workflowCardChanges,
        private FactsBuilder $factsBuilder,
        private FactProviders $providers,
        private Connection $connection,

        #[Autowire('%env(default::SENTRY_DSN)%')]
        private ?string $dsn,
    ) {
    }

    public function report(): void
    {
        if (SentryDsnStatus::Valid !== SentryDsnStatus::of($this->dsn)) {
            return;
        }

        $metrics = \Sentry\traceMetrics();
        $metrics->gauge('workflow.sweep.marked_cards', $this->workflowCardChanges->countMarked());
        $writes = $this->connection->fetchAllKeyValue(
            'SELECT relname, n_tup_ins + n_tup_upd + n_tup_del FROM pg_stat_user_tables WHERE relname IN (:tables)',
            ['tables' => $this->tables()],
            ['tables' => ArrayParameterType::STRING],
        );
        foreach ($writes as $table => $count) {
            $metrics->gauge('workflow.mark.table_writes', (int) $count, ['table' => (string) $table]);
        }
        $metrics->flush();
    }

    /** @return list<string> */
    private function tables(): array
    {
        $tables = $this->factsBuilder->sourceTables();
        foreach ($this->providers->byClass as $provider) {
            $tables = [...$tables, ...$provider->sourceTables()];
        }

        return array_values(array_unique($tables));
    }
}
