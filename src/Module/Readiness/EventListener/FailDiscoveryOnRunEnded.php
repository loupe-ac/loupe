<?php

declare(strict_types=1);

namespace App\Module\Readiness\EventListener;

use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Readiness\Command\FailDiscoveryRunCommand;
use App\Module\Readiness\Command\FailDiscoveryRunHandler;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A discovery worker ended while its run still waited for a report or a revision, so the run failed. */
#[AsEventListener]
final readonly class FailDiscoveryOnRunEnded
{
    public const string WORKER_ENDED = 'worker-ended';

    /** The work goes on in another run: a newer event took the place, or the bridge gave its claim back so the request opens again. */
    private const array CONTINUING = [WorkerRunState::Replaced, WorkerRunState::Skipped, WorkerRunState::Dropped, WorkerRunState::UsageLimit, WorkerRunState::Network];

    public function __construct(
        private WorkerRunRepository $workerRuns,
        private FailDiscoveryRunHandler $failDiscoveryRun,
    ) {
    }

    public function __invoke(WorkerRunChanged $event): void
    {
        foreach ($event->runIds as $runId) {
            $run = $this->workerRuns->find($runId);
            $cardId = $run?->cardId();
            if (null === $run || null === $cardId || !\in_array($run->ruleId, [FailDiscoveryRunHandler::RULE_ID, FailDiscoveryRunHandler::REVISE_RULE_ID], true)
                || $run->state->isOpen() || \in_array($run->state, self::CONTINUING, true)) {
                continue;
            }

            ($this->failDiscoveryRun)(new FailDiscoveryRunCommand($cardId, $run->failureReason ?? self::WORKER_ENDED, $run->ruleId));
        }
    }
}
