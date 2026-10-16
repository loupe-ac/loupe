<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkTakerReading;
use App\Module\Bridge\ValueObject\WorkTakerState;
use App\Module\Project\Entity\Project;
use Psr\Clock\ClockInterface;

/** Says, for each open work request of a project, whether an online bridge could take it. */
final readonly class WorkTakers
{
    public function __construct(
        private BridgeRepository $bridges,
        private BridgeLiveness $liveness,
        private ClockInterface $clock,
    ) {
    }

    /**
     * A claimed request has a worker already, so it gets no reading.
     *
     * @param list<WorkRequest> $requests
     *
     * @return array<string, WorkTakerReading> keyed by the RFC 4122 id of the request
     */
    public function forRequests(Project $project, array $requests): array
    {
        $open = array_values(array_filter($requests, static fn (WorkRequest $request): bool => WorkRequestState::Open === $request->state));
        if ([] === $open) {
            return [];
        }

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

        $readings = [];
        foreach ($open as $request) {
            $takers = array_values(array_filter($followers, static fn (Bridge $bridge): bool => self::takes($bridge, $request)));
            $readings[($request->id ?? throw new \LogicException('A stored request has an id.'))->toRfc4122()] = $this->read($request, $takers, $narrowedAt, $quietBefore, $window);
        }

        return $readings;
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

    /** @param list<Bridge> $takers */
    private function read(WorkRequest $request, array $takers, ?\DateTimeImmutable $narrowedAt, \DateTimeImmutable $quietBefore, int $window): WorkTakerReading
    {
        $noTakerSince = self::later($narrowedAt, $request->reopenedAt ?? $request->createdAt);
        $newestUnpaused = null;
        $newestPaused = null;
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
            } else {
                $newestUnpaused = self::later($newestUnpaused, $quietAt);
            }
        }

        // A taker is quiet from the second after its window ends, as BridgeLiveness counts whole seconds.
        return match (true) {
            null !== $newestUnpaused => new WorkTakerReading(WorkTakerState::Available, $noTakerSince, $newestUnpaused->modify('+1 second')),
            null !== $newestPaused => new WorkTakerReading(WorkTakerState::Paused, $noTakerSince, $newestPaused->modify('+1 second')),
            default => new WorkTakerReading(WorkTakerState::None, $noTakerSince, null),
        };
    }

    private static function later(?\DateTimeImmutable $a, \DateTimeImmutable $b): \DateTimeImmutable
    {
        return null === $a || $b > $a ? $b : $a;
    }
}
