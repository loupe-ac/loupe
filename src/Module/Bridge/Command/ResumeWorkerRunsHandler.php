<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Service\BridgeCommandRefusals;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use Psr\Log\LoggerInterface;

/**
 * Asks the bridges to resume a batch of runs, each on its own and in order. A
 * refusal is a row, so one refused run leaves the others.
 *
 * @phpstan-import-type ResumeRow from ResumeWorkerRunsView
 */
final readonly class ResumeWorkerRunsHandler
{
    public const int MAX_RUNS = 50;

    public const string UNKNOWN_OUTCOME = 'The outcome is unknown. Read the worker runs.';

    public function __construct(
        private RequestBridgeCommandHandler $requestCommand,
        private BridgeCommandRefusals $refusals,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ResumeWorkerRunsCommand $command): ResumeWorkerRunsView
    {
        /** @var list<ResumeRow> $results */
        $results = [];
        $stopped = false;
        foreach ($command->entries as $entry) {
            if ($stopped) {
                $results[] = ['runId' => $entry->runId, 'outcome' => 'not-attempted'];
                continue;
            }
            if (null === $entry->run) {
                $results[] = $this->refusals->notFound($entry->runId);
                continue;
            }

            try {
                $request = ($this->requestCommand)(new RequestBridgeCommandCommand(
                    run: $entry->run,
                    kind: BridgeCommandKind::ResumeRun,
                    requestedBy: $command->requestedBy,
                    cause: $command->cause,
                    apiKey: $command->apiKey,
                ));
                $results[] = ['runId' => $entry->runId, 'outcome' => 'resumed', 'commandId' => (string) $request->id];
            } catch (DomainErrors $e) {
                $results[] = $this->refusals->refused($entry->runId, $e);
            } catch (\Throwable $e) {
                // The failure may have closed the entity manager, so no later run is safe to try.
                $this->logger->error('bridge.resume_request_failed', ['runId' => $entry->runId, 'exception' => $e]);
                $results[] = ['runId' => $entry->runId, 'outcome' => 'error', 'message' => self::UNKNOWN_OUTCOME];
                $stopped = true;
            }
        }

        return new ResumeWorkerRunsView($results);
    }
}
