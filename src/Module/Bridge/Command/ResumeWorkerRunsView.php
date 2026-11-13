<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Service\BridgeCommandRefusals;

/**
 * One row per entry, in the order of the command.
 *
 * @phpstan-import-type Refusal from BridgeCommandRefusals
 *
 * @phpstan-type ResumeRow array{runId: string, outcome: 'resumed', commandId: string}|Refusal|array{runId: string, outcome: 'error', message: string}|array{runId: string, outcome: 'not-attempted'}
 */
final readonly class ResumeWorkerRunsView
{
    /** @param list<ResumeRow> $results */
    public function __construct(
        public array $results,
    ) {
    }
}
