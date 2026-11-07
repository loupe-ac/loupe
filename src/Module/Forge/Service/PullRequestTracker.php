<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Messenger\RefreshPullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Project\Entity\Project;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;

/**
 * The one way another module keeps the state of a pull request current. Every
 * write runs on the caller's connection, and the transport is Doctrine, so a
 * caller that rolls back also drops the queued refresh.
 */
final readonly class PullRequestTracker
{
    /** A merge into the base takes a moment to reach the forge's mergeability answer. */
    private const int BASE_DELAY_MILLISECONDS = 30_000;

    public function __construct(
        private ForgePullRequestRepository $forgePullRequests,
        private PullRequestStateReaders $readers,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {
    }

    public function track(Project $project, string $forge, string $repository, int $number): void
    {
        if (null === $this->readers->for($forge)) {
            return;
        }

        $this->queue($this->forgePullRequests->insertIfMissing(self::projectId($project), $forge, $repository, $number));
    }

    public function untrack(Project $project, string $forge, string $repository, int $number): void
    {
        $this->forgePullRequests->deleteByKey(self::projectId($project), $forge, $repository, $number);
    }

    /**
     * Queues one refresh for each pull request the targets name, however many
     * targets name it. The earliest delay and the first verdict win. A number
     * target reads a closed row too, because a delivery for one number can say
     * that it reopened.
     *
     * @param list<PullRequestRefreshTarget> $targets
     */
    public function refreshAll(Project $project, string $forge, string $repository, array $targets): void
    {
        $projectId = self::projectId($project);
        $plans = [];
        foreach ($targets as $target) {
            [$criteria, $openOnly, $delay] = match (true) {
                null !== $target->number => [['number' => $target->number], false, $target->delayMilliseconds],
                null !== $target->headSha => [['headSha' => $target->headSha], true, 0],
                default => [['baseBranch' => $target->baseBranch ?? throw new \LogicException('A target sets one field.')], true, self::BASE_DELAY_MILLISECONDS],
            };
            foreach ($this->forgePullRequests->findIds($projectId, $forge, $repository, $criteria, openOnly: $openOnly) as $id) {
                $key = (string) $id;
                $plans[$key] ??= ['id' => $id, 'delay' => $delay, 'verdict' => null, 'reviewId' => null];
                $plans[$key]['delay'] = min($plans[$key]['delay'], $delay);
                if (null === $plans[$key]['verdict'] && null !== $target->verdict) {
                    $plans[$key]['verdict'] = $target->verdict;
                    $plans[$key]['reviewId'] = $target->reviewId;
                }
            }
        }

        foreach ($plans as $plan) {
            $this->queue($plan['id'], $plan['delay'], $plan['verdict'], $plan['reviewId']);
        }
    }

    /** A delayed refresh is requested for when it runs, so a read inside the delay does not absorb it. */
    private function queue(Uuid $id, int $delayMilliseconds = 0, ?PullRequestReview $verdict = null, ?string $reviewId = null): void
    {
        $this->bus->dispatch(
            new RefreshPullRequestState((string) $id, $this->clock->now()->modify(\sprintf('+%d milliseconds', $delayMilliseconds)), $verdict, $reviewId),
            $delayMilliseconds > 0 ? [new DelayStamp($delayMilliseconds)] : [],
        );
    }

    private static function projectId(Project $project): Uuid
    {
        return $project->id ?? throw new \LogicException('A tracked project is persisted.');
    }
}
