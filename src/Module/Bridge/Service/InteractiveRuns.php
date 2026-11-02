<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\Messenger\CollectSessionUsage;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkerRunCloseReason;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Opens and closes the runs of interactive Claude Code sessions on a card. A
 * bridge can launch such a session but never holds its run, so only a close
 * from the session, a person or a move of the card ends it.
 *
 * Each write runs in its own transaction, which nests as a savepoint inside a
 * caller's transaction. Each close queues a request for the usage of the run
 * in that transaction, so a rollback leaves no request.
 */
final readonly class InteractiveRuns
{
    public function __construct(
        private WorkerRunRepository $workerRuns,
        private WorkRequestRepository $workRequests,
        private WorkerRunSearchIndexer $searchIndexer,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private WorkerRunChangedPublisher $publisher,
        private EventDispatcherInterface $events,
        private MessageBusInterface $bus,
    ) {
    }

    /** The open run of the session on the card, or a new one. */
    public function open(Project $project, Uuid $cardId, int $cardNumber, Uuid $sessionId, string $name, ?Uuid $bridgeId = null): WorkerRun
    {
        return $this->openUnlessFound(
            $project, WorkSubject::card($cardId), $cardNumber, $sessionId, $name, $bridgeId,
            fn (): ?WorkerRun => $this->workerRuns->findOpenInteractive($project, $cardId, $sessionId),
            beforeCreate: function () use ($cardId): void {
                if ($this->workerRuns->anyOpenWorkerRunOfCards([$cardId]) || $this->workRequests->hasClaimedNonInteractiveForCard($cardId)) {
                    throw new CardHasWorker();
                }
            },
        )[0];
    }

    /**
     * Records a session that the bridge launched, and whether this call created
     * the run. The subject is a card or an inbox item. The bridge gives each launch a new session, so any run the
     * session already has on the subject keeps its state, and takes only the
     * harness fields. A retry that arrives after a move closed the session
     * thus never opens it again.
     *
     * @return array{WorkerRun, bool}
     */
    public function recordLaunch(
        Project $project,
        WorkSubject $subject,
        ?int $cardNumber,
        Uuid $sessionId,
        string $name,
        Uuid $bridgeId,
        ?Uuid $workRequestId = null,
        ?string $ruleId = null,
        ?string $harness = null,
        ?string $account = null,
        ?string $model = null,
        ?string $harnessSessionId = null,
    ): array {
        return $this->openUnlessFound(
            $project, $subject, $cardNumber, $sessionId, $name, $bridgeId,
            fn (): ?WorkerRun => $this->workerRuns->findLatestInteractive($project, $subject->id, $sessionId, $subject->type),
            $workRequestId,
            $ruleId,
            static fn (WorkerRun $run): bool => $run->recordHarness($harness, $account, $model, $harnessSessionId),
        );
    }

    /**
     * @param \Closure(): ?WorkerRun           $find          the run that stops the open, read under the project lock
     * @param (\Closure(WorkerRun): bool)|null $recordHarness sets the harness fields, and says whether one changed
     * @param (\Closure(): void)|null          $beforeCreate  runs under the project lock when no run is found, and throws to refuse the new run
     *
     * @return array{WorkerRun, bool}
     */
    private function openUnlessFound(Project $project, WorkSubject $subject, ?int $cardNumber, Uuid $sessionId, string $name, ?Uuid $bridgeId, \Closure $find, ?Uuid $workRequestId = null, ?string $ruleId = null, ?\Closure $recordHarness = null, ?\Closure $beforeCreate = null): array
    {
        if ('' === trim($name) || mb_strlen($name) > WorkerRun::MAX_WORK_KIND_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('An interactive run needs a name of 1 to %d characters.', WorkerRun::MAX_WORK_KIND_LENGTH));
        }

        /** @var array{WorkerRun, bool, bool} $outcome */
        $outcome = $this->em->wrapInTransaction(function () use ($project, $subject, $cardNumber, $sessionId, $name, $bridgeId, $find, $workRequestId, $ruleId, $recordHarness, $beforeCreate): array {
            // The project lock serialises two opens of one session, which would
            // otherwise both miss the read and trip the unique index.
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

            $found = $find();
            if (null !== $found) {
                $changed = null !== $recordHarness && $recordHarness($found);
                if ($changed) {
                    $this->em->flush();
                }

                return [$found, false, $changed];
            }

            if (null !== $beforeCreate) {
                $beforeCreate();
            }

            $now = $this->clock->now();
            $run = new WorkerRun(
                project: $project,
                bridgeId: $bridgeId,
                subjectType: $subject->type,
                subjectId: $subject->id,
                cardNumber: $cardNumber,
                workKind: $name,
                state: WorkerRunState::Running,
                sessionId: $sessionId,
                startedAt: $now,
                receivedAt: $now,
                kind: WorkerRunKind::Interactive,
                workRequestId: $workRequestId,
                ruleId: $ruleId,
            );
            if (null !== $recordHarness) {
                $recordHarness($run);
            }
            $this->em->persist($run);
            $this->em->persist(new WorkerRunStateChange($run, WorkerRunState::Running, $now, $now));
            $this->em->flush();
            $this->searchIndexer->index($run);

            return [$run, true, true];
        });

        if ($outcome[2]) {
            $this->publisher->runsChanged($outcome[0]->project);
        }
        if ($outcome[1]) {
            $this->announce([$outcome[0]]);
        }

        return [$outcome[0], $outcome[1]];
    }

    /**
     * Records a session that the bridge failed to launch, and whether this call
     * created the row. Any run the session already has on the card keeps its
     * state, and takes only the harness fields: a retry of the report, or a
     * session that started after all.
     *
     * @return array{WorkerRun, bool}
     */
    public function recordLaunchFailure(
        Project $project,
        WorkSubject $subject,
        ?int $cardNumber,
        Uuid $sessionId,
        string $name,
        Uuid $bridgeId,
        string $failureReason,
        \DateTimeImmutable $at,
        ?Uuid $workRequestId = null,
        ?string $ruleId = null,
        ?string $harness = null,
        ?string $account = null,
        ?string $model = null,
        ?string $harnessSessionId = null,
    ): array {
        /** @var array{WorkerRun, bool, bool} $outcome */
        $outcome = $this->em->wrapInTransaction(function () use ($project, $subject, $cardNumber, $sessionId, $name, $bridgeId, $failureReason, $at, $workRequestId, $ruleId, $harness, $account, $model, $harnessSessionId): array {
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

            $existing = $this->workerRuns->findLatestInteractive($project, $subject->id, $sessionId, $subject->type);
            if (null !== $existing) {
                $changed = $existing->recordHarness($harness, $account, $model, $harnessSessionId);
                if ($changed) {
                    $this->em->flush();
                }

                return [$existing, false, $changed];
            }

            $now = $this->clock->now();
            $run = new WorkerRun(
                project: $project,
                bridgeId: $bridgeId,
                subjectType: $subject->type,
                subjectId: $subject->id,
                cardNumber: $cardNumber,
                workKind: $name,
                state: WorkerRunState::NotStarted,
                sessionId: $sessionId,
                endedAt: $at,
                failureReason: mb_substr($failureReason, 0, WorkerRun::MAX_FAILURE_REASON_LENGTH),
                receivedAt: $now,
                kind: WorkerRunKind::Interactive,
                workRequestId: $workRequestId,
                ruleId: $ruleId,
            );
            $run->recordHarness($harness, $account, $model, $harnessSessionId);
            $this->em->persist($run);
            $this->em->persist(new WorkerRunStateChange($run, WorkerRunState::NotStarted, $at, $now));
            $this->em->flush();
            $this->searchIndexer->index($run);

            return [$run, true, true];
        });

        // A retry that only names the harness reloads the Runs page and announces nothing.
        if ($outcome[2]) {
            $this->publisher->runsChanged($outcome[0]->project);
        }
        if ($outcome[1]) {
            $this->announce([$outcome[0]]);
        }

        return [$outcome[0], $outcome[1]];
    }

    /**
     * The run of the session on the card, null when it has none, and whether
     * this call closed it. A closed run comes back unchanged.
     *
     * @return array{?WorkerRun, bool}
     */
    public function close(Project $project, Uuid $cardId, Uuid $sessionId): array
    {
        return $this->closeLocked(
            fn (): ?WorkerRun => $this->workerRuns->findOpenInteractiveForUpdate($project, $cardId, $sessionId),
            fn (): ?WorkerRun => $this->workerRuns->findLatestInteractive($project, $cardId, $sessionId),
            WorkerRunCloseReason::SessionEnded,
        );
    }

    /** Null when the id names no interactive run of the project. */
    public function closeById(Project $project, Uuid $runId): ?WorkerRun
    {
        return $this->closeLocked(
            fn (): ?WorkerRun => $this->workerRuns->findOpenInteractiveByIdForUpdate($project, $runId),
            fn (): ?WorkerRun => $this->workerRuns->findInteractiveById($project, $runId),
            WorkerRunCloseReason::Person,
        )[0];
    }

    /**
     * Closes every open run of the session in every project of the owner, and returns how many it closed. One
     * `loupe mcp` session can switch projects while it runs, so the exit hook names no project.
     */
    public function closeSession(User $owner, Uuid $sessionId, WorkerRunCloseReason $reason): int
    {
        return \count($this->closeFound(
            fn (): array => $this->workerRuns->findOpenInteractiveOfOwnerSessionForUpdate($owner, $sessionId),
            $reason,
        ));
    }

    /**
     * Closes the open runs with no sign of life since $quietBefore, at most $limit of them, and returns them.
     *
     * @return list<WorkerRun>
     */
    public function closeStale(\DateTimeImmutable $quietBefore, int $limit): array
    {
        return $this->closeFound(
            fn (): array => $this->workerRuns->findQuietOpenInteractiveForUpdate($quietBefore, $limit),
            WorkerRunCloseReason::Stale,
        );
    }

    /**
     * Closes the open run of the session that the bridge launched, in any project of the owner, when it started
     * before $startedBefore. The grace period keeps a reading that predates the launch from closing a new run. A run
     * of a harness that the bridge cannot find in the process list, such as Codex, stays open.
     */
    public function closeLaunched(Uuid $bridgeId, User $owner, Uuid $sessionId, \DateTimeImmutable $startedBefore, WorkerRunCloseReason $reason): int
    {
        return \count($this->closeFound(
            fn (): array => array_values(array_filter(
                $this->workerRuns->findOpenInteractiveOfBridgeForUpdate($bridgeId, $owner, [$sessionId]),
                static fn (WorkerRun $run): bool => null !== $run->startedAt && $run->startedAt < $startedBefore
                    && \in_array($run->harness ?? '', WorkerRun::PROCESS_CHECKED_HARNESSES, true),
            )),
            $reason,
        ));
    }

    /** Stamps the open runs of the session in the project with a sign of life. */
    public function touch(Project $project, Uuid $sessionId, \DateTimeImmutable $at): void
    {
        $this->workerRuns->touchOpenInteractive($project, $sessionId, $at);
    }

    /**
     * A card that moves leaves its sessions behind. Inside a caller's
     * transaction, a rollback still leaves the reload signal queued, and the
     * page then reloads to what it already shows.
     *
     * @param list<Uuid> $cardIds
     */
    public function closeOnMove(Project $project, array $cardIds): void
    {
        if ([] === $cardIds) {
            return;
        }

        /** @var list<WorkerRun> $closed */
        $closed = $this->em->wrapInTransaction(function () use ($project, $cardIds): array {
            $runs = $this->workerRuns->findOpenInteractiveOfCardsForUpdate($project, $cardIds);
            $at = $this->clock->now();
            foreach ($runs as $run) {
                $this->closeRun($run, $at, WorkerRunCloseReason::CardMoved);
            }
            $this->em->flush();

            return $runs;
        });

        if ([] !== $closed) {
            $this->publisher->runsChanged($closed[0]->project);
            $this->announce($closed);
        }
    }

    public function hasOpenRun(Project $project, Uuid $cardId): bool
    {
        return $this->workerRuns->hasOpenInteractive($project, $cardId);
    }

    /**
     * Doctrine does not refresh a run it already manages, so the locked read
     * filters on the running state, which Postgres checks again under the lock.
     *
     * @param \Closure(): ?WorkerRun $findOpenLocked
     * @param \Closure(): ?WorkerRun $findAny
     *
     * @return array{?WorkerRun, bool}
     */
    private function closeLocked(\Closure $findOpenLocked, \Closure $findAny, WorkerRunCloseReason $reason): array
    {
        /** @var array{?WorkerRun, bool} $outcome */
        $outcome = $this->em->wrapInTransaction(function () use ($findOpenLocked, $findAny, $reason): array {
            $run = $findOpenLocked();
            if (null === $run) {
                $run = $findAny();
                // The locked read found no running row, so a running copy is stale.
                if (WorkerRunState::Running === $run?->state) {
                    $this->em->detach($run);
                    $run = $findAny();
                }

                return [$run, false];
            }

            $this->closeRun($run, $this->clock->now(), $reason);
            $this->em->flush();

            return [$run, true];
        });

        [$run, $closed] = $outcome;
        if ($closed && null !== $run) {
            $this->publisher->runsChanged($run->project);
            $this->announce([$run]);
        }

        return $outcome;
    }

    /**
     * @param \Closure(): list<WorkerRun> $findLocked
     *
     * @return list<WorkerRun>
     */
    private function closeFound(\Closure $findLocked, WorkerRunCloseReason $reason): array
    {
        /** @var list<WorkerRun> $closed */
        $closed = $this->em->wrapInTransaction(function () use ($findLocked, $reason): array {
            $runs = $findLocked();
            $at = $this->clock->now();
            foreach ($runs as $run) {
                $this->closeRun($run, $at, $reason);
            }
            $this->em->flush();

            return $runs;
        });

        $projects = [];
        foreach ($closed as $run) {
            $projects[(string) $run->project->id] = $run->project;
        }
        foreach ($projects as $project) {
            $this->publisher->runsChanged($project);
        }
        if ([] !== $closed) {
            $this->announce($closed);
        }

        return $closed;
    }

    /** @param list<WorkerRun> $runs */
    private function announce(array $runs): void
    {
        foreach (WorkerRunChanged::ofRuns($runs) as $event) {
            $this->events->dispatch($event);
        }
    }

    private function closeRun(WorkerRun $run, \DateTimeImmutable $at, WorkerRunCloseReason $reason): void
    {
        $run->moveTo(WorkerRunState::Closed);
        $run->endedAt = $at;
        $run->closeReason = $reason;
        $this->em->persist(new WorkerRunStateChange($run, WorkerRunState::Closed, $at, $at));
        $this->bus->dispatch(new CollectSessionUsage((string) $run->project->id, (string) $run->id));
    }
}
