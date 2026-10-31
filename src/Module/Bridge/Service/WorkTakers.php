<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Repository\BridgeWorkMatchRepository;
use App\Module\Bridge\ValueObject\AccountLimit;
use App\Module\Bridge\ValueObject\UsageLimitWait;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkTakerReading;
use App\Module\Bridge\ValueObject\WorkTakerState;
use App\Module\Project\Entity\Project;
use App\Security\LoginLivenessInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** Says, for each open work request of a project, whether an online bridge could take it. */
final readonly class WorkTakers
{
    public function __construct(
        private BridgeRepository $bridges,
        private BridgeLiveness $liveness,
        private ClockInterface $clock,
        private LoginLivenessInterface $logins,
        private AccountLimits $accountLimits,
        private BridgeWorkMatchRepository $bridgeWorkMatches,
    ) {
    }

    /**
     * A claimed request has a worker already, so it gets a reading only when its bridge went quiet. The same goes for
     * an open request that waits after a network outage.
     *
     * @param list<WorkRequest> $requests
     *
     * @return array<string, WorkTakerReading> keyed by the RFC 4122 id of the request
     */
    public function forRequests(Project $project, array $requests): array
    {
        $live = array_values(array_filter($requests, static fn (WorkRequest $request): bool => \in_array($request->state, [WorkRequestState::Open, WorkRequestState::Claimed], true)));
        if ([] === $live) {
            return [];
        }

        [$followers, $narrowedAt, $quietBefore, $window, $expiredIds] = $this->followersOf($project);

        $open = array_values(array_filter($live, static fn (WorkRequest $request): bool => WorkRequestState::Open === $request->state));
        $matches = [] === $open ? [] : $this->bridgeWorkMatches->accountsOfRequests(array_map(static fn (WorkRequest $request): Uuid => $request->id ?? throw new \LogicException('A stored request has an id.'), $open));
        $blocked = [];
        $readings = [];
        foreach ($live as $request) {
            $requestId = ($request->id ?? throw new \LogicException('A stored request has an id.'))->toRfc4122();
            $disconnected = $this->disconnected($project, $request, $followers, $quietBefore, $window);
            if (null !== $disconnected) {
                $readings[$requestId] = $disconnected;

                continue;
            }
            if (WorkRequestState::Open !== $request->state) {
                continue;
            }

            $takers = array_values(array_filter($followers, static fn (Bridge $bridge): bool => self::takes($bridge, $request)));
            // Offers go to the target bridge alone, so no other bridge counts, also when the target cannot take the work.
            $targetId = $request->targetBridgeId;
            if (null !== $targetId) {
                $takers = array_values(array_filter($takers, static fn (Bridge $bridge): bool => $targetId->equals($bridge->id)));
            }
            $limited = [];
            foreach ($takers as $taker) {
                if ($taker->lastSeenAt < $quietBefore || $taker->pauseRequested || true === $taker->pausedReported) {
                    continue;
                }
                $blocked[$taker->id->toRfc4122()] ??= $this->accountLimits->blockedOf($taker);
                $accounts = self::limitedAccounts($taker, $request, $matches[$requestId][$taker->id->toRfc4122()] ?? null, $blocked[$taker->id->toRfc4122()]);
                if ([] !== $accounts) {
                    $limited[$taker->id->toRfc4122()] = $accounts;
                }
            }
            $reading = $this->read($request->reopenedAt ?? $request->createdAt, $followers, $takers, $narrowedAt, $quietBefore, $window, null !== $request->prompt && '' !== trim($request->prompt), $expiredIds, $limited);
            $readings[$requestId] = self::stillDisconnected($request, $followers, $quietBefore, $reading) ?? $reading;
        }

        return $readings;
    }

    /**
     * Disconnected when the bridge of the claim sent no heartbeat within the liveness window, or when the request
     * waits after a network outage. The reading changes at the end of the claim or of the wait.
     *
     * @param list<Bridge> $followers
     */
    private function disconnected(Project $project, WorkRequest $request, array $followers, \DateTimeImmutable $quietBefore, int $window): ?WorkTakerReading
    {
        if (WorkRequestState::Claimed === $request->state) {
            $bridge = null === $request->bridgeId ? null : $this->bridges->findOneByOwnerAndId($project->owner, $request->bridgeId);
            if (null === $bridge || $bridge->lastSeenAt >= $quietBefore) {
                return null;
            }

            return new WorkTakerReading(WorkTakerState::Disconnected, $bridge->lastSeenAt->modify(\sprintf('+%d seconds', $window)), $request->leaseUntil, bridgeLabel: $bridge->label, bridgeSeenAt: $bridge->lastSeenAt, claimed: true);
        }

        if (WorkRequest::WAIT_NETWORK !== $request->waitCause || null === $request->waitUntil || $request->waitUntil <= $this->clock->now()) {
            return null;
        }

        $targetId = $request->targetBridgeId;
        $target = null === $targetId ? null : array_find($followers, static fn (Bridge $bridge): bool => $targetId->equals($bridge->id));

        return new WorkTakerReading(WorkTakerState::Disconnected, $request->reopenedAt ?? $request->createdAt, $request->waitUntil, bridgeLabel: $target?->label, bridgeSeenAt: $target?->lastSeenAt);
    }

    /**
     * After the network wait, the outage still holds the work while its target bridge stays quiet, or while no bridge
     * is online at all. An expired login or a target that left the project keeps its own reading.
     *
     * @param list<Bridge> $followers
     */
    private static function stillDisconnected(WorkRequest $request, array $followers, \DateTimeImmutable $quietBefore, WorkTakerReading $reading): ?WorkTakerReading
    {
        if (WorkRequest::WAIT_NETWORK !== $request->waitCause || [] !== $reading->expiredTakers) {
            return null;
        }

        $targetId = $request->targetBridgeId;
        $target = null === $targetId ? null : array_find($followers, static fn (Bridge $bridge): bool => $targetId->equals($bridge->id));
        $targetQuiet = null !== $target && $target->lastSeenAt < $quietBefore;
        if (!$targetQuiet && WorkTakerState::None !== $reading->state) {
            return null;
        }

        return new WorkTakerReading(WorkTakerState::Disconnected, $request->reopenedAt ?? $request->createdAt, null, bridgeLabel: $target?->label, bridgeSeenAt: $target?->lastSeenAt);
    }

    /**
     * The blocked accounts that keep the bridge from starting the request, or none when it can start. The account that
     * the bridge matched to the request decides. A request that the bridge has not matched yet waits only when every
     * account that runs its kind is blocked, and a bridge that lists no account for the kind can start it.
     *
     * @param array<string, AccountLimit> $blocked the blocked accounts of the bridge by name
     *
     * @return list<AccountLimit>
     */
    private static function limitedAccounts(Bridge $bridge, WorkRequest $request, ?string $matched, array $blocked): array
    {
        if (null !== $matched) {
            return isset($blocked[$matched]) ? [$blocked[$matched]] : [];
        }

        $workKind = Bridge::workKindOf($request->subjectType, $request->kind);
        $limits = [];
        foreach ($bridge->accounts ?? [] as $row) {
            if (!\in_array($workKind, $row['kinds'] ?? [], true)) {
                continue;
            }
            if (!isset($blocked[$row['name']])) {
                return [];
            }
            $limits[] = $blocked[$row['name']];
        }

        return $limits;
    }

    /**
     * The reading for work of $kind that the project would request now, with a prompt or without. The readiness
     * guide and the start of a discovery use it, so they agree with the engine that holds the real request.
     */
    public function capableFor(Project $project, string $kind, bool $hasPrompt): WorkTakerReading
    {
        [$followers, $narrowedAt, $quietBefore, $window, $expiredIds] = $this->followersOf($project);
        $takers = array_values(array_filter($followers, static fn (Bridge $bridge): bool => $bridge->takesWorkRequests()
            && (\in_array($kind, $bridge->workKinds, true) || ($hasPrompt && \in_array(Bridge::CAPABILITY_APP_PROMPTS, $bridge->capabilities ?? [], true)))));

        return $this->read($this->clock->now(), $followers, $takers, $narrowedAt, $quietBefore, $window, $hasPrompt, $expiredIds);
    }

    /** @return array{list<Bridge>, ?\DateTimeImmutable, \DateTimeImmutable, int, list<string>} the bridges that follow the project, the latest narrowing, the quiet boundary, the liveness window in seconds and the RFC 4122 ids of the quiet followers whose login expired */
    private function followersOf(Project $project): array
    {
        $now = $this->clock->now();
        $quietBefore = $this->liveness->quietBefore($now);
        $window = $now->getTimestamp() - $quietBefore->getTimestamp();
        $projectId = ($project->id ?? throw new \LogicException('A stored project has an id.'))->toRfc4122();
        // A bridge that left the project still bounds the wait of the work it left.
        $bridges = $this->bridges->findByOwner($project->owner);
        $narrowedAt = null;
        foreach ($bridges as $bridge) {
            if (null !== $bridge->narrowedAt) {
                $narrowedAt = self::later($narrowedAt, $bridge->narrowedAt);
            }
        }
        $followers = array_values(array_filter($bridges, static fn (Bridge $bridge): bool => \in_array($projectId, $bridge->projects, true)));

        $quietLogins = [];
        foreach ($followers as $bridge) {
            if (null !== $bridge->loginId && $bridge->lastSeenAt < $quietBefore) {
                $quietLogins[$bridge->id->toRfc4122()] = $bridge->loginId;
            }
        }

        $expiredLogins = [] === $quietLogins ? [] : array_map(static fn (Uuid $id): string => $id->toRfc4122(), $this->logins->expired(array_values($quietLogins)));
        $expiredIds = array_keys(array_filter($quietLogins, static fn (Uuid $login): bool => \in_array($login->toRfc4122(), $expiredLogins, true)));

        return [$followers, $narrowedAt, $quietBefore, $window, $expiredIds];
    }

    private static function takes(Bridge $bridge, WorkRequest $request): bool
    {
        return $bridge->canRun($request)
            && (\in_array(Bridge::workKindOf($request->subjectType, $request->kind), $bridge->workKinds, true)
                || self::runsAsAppPrompt($bridge, $request));
    }

    /** The bridge runs an unmapped kind only from the prompt of the request, so a request with no prompt needs its kind. */
    private static function runsAsAppPrompt(Bridge $bridge, WorkRequest $request): bool
    {
        return null !== $request->prompt
            && '' !== trim($request->prompt)
            && \in_array(Bridge::CAPABILITY_APP_PROMPTS, $bridge->capabilities ?? [], true);
    }

    /**
     * @param list<Bridge>                                $followers  every bridge that follows the project
     * @param list<Bridge>                                $takers     the followers that could run the work
     * @param list<string>                                $expiredIds the RFC 4122 ids of the quiet followers whose login expired
     * @param array<string, non-empty-list<AccountLimit>> $limited    the blocked accounts that keep a taker from the work, by RFC 4122 bridge id
     */
    private function read(\DateTimeImmutable $waitStart, array $followers, array $takers, ?\DateTimeImmutable $narrowedAt, \DateTimeImmutable $quietBefore, int $window, bool $hasPrompt, array $expiredIds, array $limited = []): WorkTakerReading
    {
        $expiredTakers = array_values(array_map(static fn (Bridge $bridge): string => $bridge->label, array_filter($takers, static fn (Bridge $bridge): bool => \in_array($bridge->id->toRfc4122(), $expiredIds, true))));
        $noTakerSince = self::later($narrowedAt, $waitStart);
        $newestUnpaused = null;
        $newestPaused = null;
        $newestLimited = null;
        $limits = [];
        $newestTaker = null;
        foreach ($takers as $taker) {
            $quietAt = $taker->lastSeenAt->modify(\sprintf('+%d seconds', $window));
            if ($quietAt > $noTakerSince) {
                $noTakerSince = $quietAt;
            }
            if ($taker->lastSeenAt < $quietBefore) {
                continue;
            }
            if ($taker->pauseRequested || true === $taker->pausedReported) {
                $newestPaused = self::later($newestPaused, $quietAt);
            } elseif (isset($limited[$taker->id->toRfc4122()])) {
                $newestLimited = self::later($newestLimited, $quietAt);
                $limits = [...$limits, ...$limited[$taker->id->toRfc4122()]];
            } else {
                $newestUnpaused = self::later($newestUnpaused, $quietAt);
                if (null === $newestTaker || $taker->lastSeenAt > $newestTaker->lastSeenAt) {
                    $newestTaker = $taker;
                }
            }
        }

        // A taker is quiet from the second after its window ends, as BridgeLiveness counts whole seconds.
        return match (true) {
            null !== $newestUnpaused => new WorkTakerReading(WorkTakerState::Available, $noTakerSince, $newestUnpaused->modify('+1 second'), bridgeLabel: $newestTaker?->label, bridgeSeenAt: $newestTaker?->lastSeenAt),
            null !== $newestLimited && [] !== $limits => self::limitedReading($noTakerSince, $newestLimited->modify('+1 second'), new UsageLimitWait($limits)),
            null !== $newestPaused => new WorkTakerReading(WorkTakerState::Paused, $noTakerSince, $newestPaused->modify('+1 second')),
            default => self::withoutTaker($followers, $noTakerSince, $quietBefore, $hasPrompt, $expiredTakers),
        };
    }

    /** The answer changes at the first reset, or sooner when the newest limited bridge turns quiet. */
    private static function limitedReading(\DateTimeImmutable $noTakerSince, \DateTimeImmutable $quietAt, UsageLimitWait $limit): WorkTakerReading
    {
        return new WorkTakerReading(WorkTakerState::Limited, $noTakerSince, min($quietAt, $limit->resetsAt), limit: $limit);
    }

    /**
     * Online followers that none of the takers matched are bridges that cannot run the work.
     *
     * @param list<Bridge> $followers
     * @param list<string> $expiredTakers
     */
    private static function withoutTaker(array $followers, \DateTimeImmutable $noTakerSince, \DateTimeImmutable $quietBefore, bool $hasPrompt, array $expiredTakers): WorkTakerReading
    {
        $online = array_values(array_filter($followers, static fn (Bridge $bridge): bool => $bridge->lastSeenAt >= $quietBefore));
        if ([] === $online) {
            return new WorkTakerReading(WorkTakerState::None, $noTakerSince, null, expiredTakers: $expiredTakers);
        }

        $missing = $hasPrompt && array_any($online, static fn (Bridge $bridge): bool => !\in_array(Bridge::CAPABILITY_APP_PROMPTS, $bridge->capabilities ?? [], true));
        usort($online, static fn (Bridge $a, Bridge $b): int => $b->lastSeenAt <=> $a->lastSeenAt);

        return new WorkTakerReading(WorkTakerState::NotCapable, $noTakerSince, null, $missing, $online[0]->label, $online[0]->lastSeenAt, $expiredTakers);
    }

    private static function later(?\DateTimeImmutable $a, \DateTimeImmutable $b): \DateTimeImmutable
    {
        return null === $a || $b > $a ? $b : $a;
    }
}
