<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Event\BridgeAvailabilityChanged;
use App\Module\Bridge\Event\BridgeNameChanged;
use App\Module\Bridge\Event\BridgeReported;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\Repository\BridgeHostSampleRepository;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\BridgeLiveness;
use App\Module\Bridge\Service\CliCompatibility;
use App\Module\Bridge\Service\HeldProjects;
use App\Module\Bridge\Service\HostSampling;
use App\Module\Bridge\Service\InteractiveRuns;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use App\Module\Bridge\Service\WorkerRunFactWriter;
use App\Module\Bridge\Service\WorkerRunRetentionPolicy;
use App\Module\Bridge\Service\WorkRequestLease;
use App\Module\Bridge\ValueObject\BridgeHostSampleReport;
use App\Module\Bridge\ValueObject\BridgeSessionReading;
use App\Module\Bridge\ValueObject\WorkerRunCloseReason;
use App\Module\Project\Repository\ProjectRepository;
use App\Security\LoginLivenessInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Creates or replaces the owner's row for one bridge. Another account's row
 * under the same bridge id is a different row and stays as it is.
 *
 * @phpstan-import-type RepositoryRow from Bridge
 */
final readonly class RecordBridgeHeartbeatHandler
{
    /** The reply repeats the outbox offers, so it needs no more than a bridge can start soon. */
    public const int MAX_WORK_REQUEST_OFFERS = 100;

    /** A run younger than this stays open on an alive: false reading, which may predate the launch. */
    private const string SESSION_GONE_GRACE = '-2 minutes';

    /** How far ahead of the server clock a sample may be, to allow for a small clock drift. */
    private const string MAX_SAMPLE_LEAD = '+5 minutes';

    public function __construct(
        private BridgeRepository $bridges,
        private BridgeCommandRepository $bridgeCommands,
        private WorkRequestRepository $workRequests,
        private BridgeHostSampleRepository $bridgeHostSamples,
        private HostSampling $hostSampling,
        private WorkerRunRepository $workerRuns,
        private WorkerRunFactWriter $factWriter,
        private InteractiveRuns $interactiveRuns,
        private WorkerRunRetentionPolicy $retention,
        private WorkRequestLease $lease,
        private ProjectRepository $projects,
        private BridgeLiveness $liveness,
        private WorkerRunChangedPublisher $runsChanged,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
        private HeldProjects $heldProjects,
        private LoginLivenessInterface $logins,
    ) {
    }

    public function __invoke(RecordBridgeHeartbeatCommand $command): RecordBridgeHeartbeatResult
    {
        $ownerId = (string) ($command->owner->id ?? throw new \LogicException('An authenticated user always has an id.'));
        $owned = $this->projects->findIdsOwnedBy($command->owner, $command->projects);
        $projects = array_values(array_filter($command->projects, static fn (string $id): bool => \in_array($id, $owned, true)));
        // A bridge keeps the flag value it read at connect, so it can still send samples after sampling went off.
        $samples = $this->hostSampling->enabled() ? $this->samplesInWindow($command->hostSamples) : [];

        // Two first heartbeats of one bridge would otherwise both miss the read
        // and one would trip the primary key.
        [$bridge, $created, $commands, $pauseChanged, $workRequests, $lostClaims, $renamedIn, $poolsChanged, $followedBefore, $wasQuiet, $availabilityChanged] = $this->em->wrapInTransaction(function () use ($command, $ownerId, $projects, $samples): array {
            $this->bridges->lockForWrite($ownerId, $command->bridgeId);

            $now = $this->clock->now();
            $bridge = $this->bridges->findOneByOwnerAndId($command->owner, $command->bridgeId);
            $created = null === $bridge;
            $followed = $bridge->projects ?? [];
            $wasQuiet = null !== $bridge && $bridge->lastSeenAt < $this->liveness->quietBefore($now);
            $pausedBefore = true === $bridge?->pausedReported;
            $capabilitiesBefore = $bridge?->capabilities;
            $kindsBefore = $bridge->workKinds ?? [];
            $repositoriesBefore = $bridge->repositories ?? [];
            if (null === $bridge) {
                $bridge = new Bridge($command->owner, $command->bridgeId, $projects, $command->cliVersion, $now);
                $bridge->hooks = $command->hooks ?? [];
                $this->em->persist($bridge);
            } else {
                $bridge->projects = $projects;
                $bridge->cliVersion = $command->cliVersion;
                $bridge->lastSeenAt = $now;
                if (null !== $command->hooks) {
                    $bridge->hooks = $command->hooks;
                }
            }

            $bridge->updateState = $command->updateState;
            $bridge->updateVersion = $command->updateVersion;
            $bridge->installMethod = $command->installMethod;
            if (null !== $command->accessTokenId && $bridge->loginAccessTokenId !== $command->accessTokenId) {
                $bridge->loginAccessTokenId = $command->accessTokenId;
                $bridge->loginId = $this->logins->loginOfAccessToken($command->accessTokenId);
            }
            $poolsChanged = null !== $command->workerPools && $bridge->workerPools !== $command->workerPools;
            if (null !== $command->workerPools) {
                $bridge->workerPools = $command->workerPools;
                $bridge->workerPoolsReportedAt = $now;
            }
            if (null !== $command->accounts) {
                $bridge->accounts = $command->accounts;
                $bridge->accountsReportedAt = $now;
            }
            if (null !== $command->commandEntries) {
                $bridge->commandEntries = $command->commandEntries;
                $bridge->commandEntriesReportedAt = $now;
            }
            if (null !== $command->worktrees) {
                $bridge->worktrees = $command->worktrees;
                $bridge->worktreesReportedAt = $now;
            }
            if (null !== $command->repositories) {
                $bridge->repositories = $command->repositories;
                $bridge->repositoriesReportedAt = $now;
            }
            $pauseChanged = null !== $command->paused && $bridge->pausedReported !== $command->paused;
            if (null !== $command->paused) {
                $bridge->pausedReported = $command->paused;
            }
            if (null !== $command->capabilities) {
                $bridge->capabilities = $command->capabilities;
            }
            $bridge->workKinds = $command->workKinds;
            if (null !== $command->gitIdentities) {
                $bridge->gitIdentities = $command->gitIdentities;
                $bridge->gitIdentitiesReportedAt = $now->modify(\sprintf('-%d seconds', $command->gitIdentitiesAge));
            } elseif (!\in_array(Bridge::CAPABILITY_GIT_IDENTITIES, $command->capabilities ?? [], true)) {
                // An older CLI pushes with the git setup of the machine, so the rows of a newer one no longer hold.
                $bridge->gitIdentities = null;
                $bridge->gitIdentitiesReportedAt = null;
            }
            // A new bridge, a bridge back from quiet and a bridge that reports other repositories change what the waiting cards can expect.
            $availabilityChanged = $created
                || $wasQuiet
                || $pausedBefore !== (true === $bridge->pausedReported)
                || (null !== $command->capabilities && self::sorted($capabilitiesBefore ?? []) !== self::sorted($command->capabilities))
                || self::sorted($kindsBefore) !== self::sorted($bridge->workKinds)
                || (null !== $command->repositories && self::repositoriesChanged($repositoriesBefore, $command->repositories))
                || [] !== array_diff($followed, $projects)
                || [] !== array_diff($projects, $followed);
            // An online bridge that drops what it takes starts the no-taker wait of the work it leaves.
            if (!$created && !$wasQuiet && (
                [] !== array_diff($kindsBefore, $bridge->workKinds)
                || [] !== array_diff($capabilitiesBefore ?? [], $bridge->capabilities ?? [])
                || [] !== array_diff($followed, $projects)
            )) {
                $bridge->narrowedAt = $now;
            }
            $heldName = $bridge->name;
            if ('' === $command->name) {
                // A release holds the lock until it commits, so a claim waiting on it reads the free name.
                if (null !== $bridge->name) {
                    $this->bridges->lockNamesForWrite($ownerId);
                }
                $bridge->name = null;
                $bridge->requestedName = null;
            } elseif (null !== $command->name) {
                $bridge->requestedName = $command->name;
                // The unique index keeps a held name off every other bridge, so only a new claim needs the check.
                if ($bridge->name !== $command->name) {
                    // Always taken after the bridge lock, so two heartbeats never wait on each other in reverse.
                    $this->bridges->lockNamesForWrite($ownerId);
                    $bridge->name = $this->bridges->isNameHeldByOther($command->owner, $command->bridgeId, $command->name) ? null : $command->name;
                }
            }

            if ([] !== $samples) {
                // The sample rows point at the bridge row, so a new bridge must reach the table first.
                $this->em->flush();
                $this->bridgeHostSamples->insertNew($command->owner->id ?? throw new \LogicException('An authenticated user always has an id.'), $command->bridgeId, $samples);
            }

            $lostClaims = [];
            if (null !== $command->workClaims) {
                $renewed = $this->workRequests->renewLeases($ownerId, $command->bridgeId, $command->workClaims, $this->lease->until($now));
                $named = array_values(array_unique(array_map(static fn (array $claim): string => $claim[0]->toRfc4122(), $command->workClaims)));
                $lostClaims = array_values(array_diff($named, $renewed));
            }
            $workRequests = $bridge->takesWorkRequests()
                ? $this->workRequests->findOpenOffers($bridge->id, $bridge->projects, $bridge->capabilities ?? [], self::MAX_WORK_REQUEST_OFFERS, $this->heldProjects->among($bridge->projects))
                : [];

            // Read under the lock a new command takes, so the reply misses no command stored before it.
            return [$bridge, $created, $this->bridgeCommands->findPendingFor($command->owner, $command->bridgeId, $now), $pauseChanged, $workRequests, $lostClaims,
                // A project the bridge stopped following can still hold a notice that names it.
                $heldName === $bridge->name ? [] : array_values(array_unique([...$followed, ...$projects])),
                $poolsChanged,
                $followed,
                $wasQuiet,
                $availabilityChanged,
            ];
        });

        // After the commit, because the writer locks run rows and the bridge lock must not wait on them.
        // A resent batch inserts nothing and still recomputes, so a failed recompute gets a retry.
        if ([] !== $samples) {
            $times = array_map(static fn (BridgeHostSampleReport $sample): int => $sample->sampledAt->getTimestamp(), $samples);
            $this->factWriter->upsert($this->workerRuns->findEndedIdsOnBridgeBetween(
                $command->owner->id ?? throw new \LogicException('An authenticated user always has an id.'),
                $command->bridgeId,
                new \DateTimeImmutable('@'.min($times)),
                new \DateTimeImmutable('@'.max($times)),
            ));
        }

        // The Agents pill of the sidebar sums the pools of the live bridges that follow a project, so it reloads
        // when the pools change, the bridge follows another set or comes back from quiet, on the projects it left as well.
        $followedChanged = [] !== array_diff($followedBefore, $projects) || [] !== array_diff($projects, $followedBefore);
        $cameBack = $wasQuiet && null !== $bridge->workerPools;
        if ($pauseChanged || $poolsChanged || $followedChanged || $cameBack) {
            foreach ($this->projects->findOwnedBy($command->owner, array_values(array_unique([...$followedBefore, ...$projects]))) as $project) {
                $this->runsChanged->runsChanged($project);
            }
        }

        if ($availabilityChanged) {
            $this->events->dispatch(new BridgeAvailabilityChanged(array_values(array_unique([...$followedBefore, ...$projects]))));
        }

        $watchSessions = $this->applySessionReadings($command);

        $this->events->dispatch(new BridgeReported(array_values(array_unique([...$followedBefore, ...$projects]))));

        if ([] !== $renamedIn) {
            $this->events->dispatch(new BridgeNameChanged($this->projects->findOwnedBy($command->owner, $renamedIn)));
        }

        // A heartbeat that replaces the row is routine traffic, once a minute per
        // bridge, so only the first one reaches the audit trail.
        if ($created) {
            $this->auditor->record(
                'bridge.bridge_registered',
                AuditOutcome::Success,
                ['bridgeId' => (string) $bridge->id, 'projects' => \count($bridge->projects)],
                new AuditSubject('bridge', (string) $bridge->id),
            );
        }

        return new RecordBridgeHeartbeatResult($bridge, CliCompatibility::RANGE, $commands, $workRequests, $lostClaims, $watchSessions);
    }

    /**
     * Applies the readings to the open interactive runs that this bridge launched, in any project of its owner, and
     * returns the sessions it has to report on next. A reading for any other session changes nothing.
     *
     * @return list<string> RFC 4122 session ids
     */
    private function applySessionReadings(RecordBridgeHeartbeatCommand $command): array
    {
        $now = $this->clock->now();
        $readings = [];
        foreach ($command->interactiveSessions as $reading) {
            $readings[$reading->sessionId->toRfc4122()] = $reading;
        }

        if ([] !== $readings) {
            $this->em->wrapInTransaction(function () use ($command, $readings, $now): void {
                $runs = $this->workerRuns->findOpenInteractiveOfBridgeForUpdate($command->bridgeId, $command->owner, array_map(static fn (BridgeSessionReading $reading): Uuid => $reading->sessionId, array_values($readings)));
                foreach ($runs as $run) {
                    $reading = $readings[$run->sessionId?->toRfc4122() ?? ''] ?? null;
                    $activity = null === $reading ? null : self::activityOf($reading, $now);
                    if (null !== $activity && (null === $run->lastActivityAt || $run->lastActivityAt < $activity)) {
                        $run->lastActivityAt = $activity;
                    }
                }
                $this->em->flush();
            });

            foreach ($readings as $reading) {
                if (false === $reading->alive) {
                    $this->interactiveRuns->closeLaunched($command->bridgeId, $command->owner, $reading->sessionId, $now->modify(self::SESSION_GONE_GRACE), WorkerRunCloseReason::SessionGone);
                }
            }
        }

        return $this->workerRuns->findOpenInteractiveSessionIdsOfBridge($command->bridgeId, $command->owner);
    }

    /** A live process is a sign of life now. Otherwise the transcript time counts, never later than now. */
    private static function activityOf(BridgeSessionReading $reading, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if (true === $reading->alive) {
            return $now;
        }

        return null === $reading->transcriptAt ? null : min($reading->transcriptAt, $now);
    }

    /**
     * @param list<string> $items
     *
     * @return list<string>
     */
    private static function sorted(array $items): array
    {
        sort($items);

        return $items;
    }

    /**
     * @param list<RepositoryRow> $before
     * @param list<RepositoryRow> $after
     */
    private static function repositoriesChanged(array $before, array $after): bool
    {
        $rows = static fn (array $list): array => self::sorted(array_values(array_map(static fn (array $row): string => json_encode([$row['projectId'], $row['path'], $row['remoteUrl']], \JSON_THROW_ON_ERROR), $list)));

        return $rows($before) !== $rows($after);
    }

    /**
     * Drops the samples of a bridge with a wrong clock, so they neither escape the purge
     * nor widen the recompute window to every run of the bridge.
     *
     * @param list<BridgeHostSampleReport> $samples
     *
     * @return list<BridgeHostSampleReport>
     */
    private function samplesInWindow(array $samples): array
    {
        $now = $this->clock->now();
        $oldest = $now->sub(new \DateInterval('P'.$this->retention->retentionDays().'D'));
        $latest = $now->modify(self::MAX_SAMPLE_LEAD);

        return array_values(array_filter(
            $samples,
            static fn (BridgeHostSampleReport $sample): bool => $sample->sampledAt >= $oldest && $sample->sampledAt <= $latest,
        ));
    }
}
