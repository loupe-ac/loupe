<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Entity\WorkerWait;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkerWaitRepository;
use App\Module\Bridge\ValueObject\BridgeCommandCause;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\WorkerRunState;
use Psr\Log\LoggerInterface;

/**
 * Asks the bridge of a run to resume the session of a wait that ended. Loupe requests the resume, so it names no person.
 * A wait that a run resumed already, or that ended in a way that does not resume, gets none. A run that still runs gets
 * its resume when it ends, unless it ends finished or blocked. A resume that the request refuses leaves the wait ended with no resume run.
 */
final readonly class ResumeWaitingRunHandler
{
    public function __construct(
        private WorkerWaitRepository $workerWaits,
        private WorkerRunRepository $workerRuns,
        private BridgeCommandRepository $bridgeCommands,
        private RequestBridgeCommandHandler $requestCommand,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ResumeWaitingRunCommand $command): void
    {
        $wait = $this->workerWaits->find($command->waitId);
        $context = ['waitId' => $command->waitId->toRfc4122()];
        if (null === $wait || null === $wait->endedAt || null === $wait->endReason || !$wait->endReason->resumesSession()) {
            $this->logger->info('bridge.wait_resume_skipped', $context + ['reason' => 'not_ended_for_a_resume']);

            return;
        }
        if (null !== $wait->resumeRunId) {
            $this->logger->info('bridge.wait_resume_skipped', $context + ['reason' => 'already_resumed']);

            return;
        }
        $run = $this->workerRuns->find($wait->runId);
        if (null === $run) {
            $this->logger->info('bridge.wait_resume_skipped', $context + ['reason' => 'no_run']);

            return;
        }
        // A live run takes no resume. Its end asks again, as it does for an ask that closed meanwhile.
        if ($run->state->isOpen()) {
            $this->logger->info('bridge.wait_resume_skipped', $context + ['reason' => 'run_live']);

            return;
        }
        // A run that ended finished or blocked after its wait ended reported that it needs no resume.
        if (WorkerRunState::Waiting !== $run->state) {
            $this->logger->info('bridge.wait_resume_skipped', $context + ['reason' => 'run_not_waiting']);

            return;
        }
        // An expired, refused or cancelled resume never reached the session, so only a pending or a done one counts.
        if ($this->bridgeCommands->hasResumeOfRunSince($run, $wait->endedAt, [BridgeCommandState::Pending, BridgeCommandState::Done])) {
            $this->logger->info('bridge.wait_resume_skipped', $context + ['reason' => 'already_requested']);

            return;
        }

        try {
            ($this->requestCommand)(new RequestBridgeCommandCommand(
                $run,
                BridgeCommandKind::ResumeRun,
                null,
                cause: BridgeCommandCause::WaitEnded,
                wait: self::resultOf($wait),
            ));
        } catch (DomainErrors $e) {
            $this->logger->info('bridge.wait_resume_skipped', $context + ['runId' => (string) $run->id, 'reason' => implode(',', $e->errors)]);
        }
    }

    /**
     * What the resume prompt names: why the wait ended, and the conditions that were true. Keys and tokens only,
     * because a command never carries text that a person wrote.
     *
     * @return array{reason: string, conditions: list<array{key: string, pullRequest: ?string}>}
     */
    private static function resultOf(WorkerWait $wait): array
    {
        return [
            'reason' => ($wait->endReason ?? throw new \LogicException('A resumed wait has ended.'))->value,
            'conditions' => array_map(static fn (array $entry): array => ['key' => $entry['key'], 'pullRequest' => $entry['token']], $wait->metConditions ?? []),
        ];
    }
}
