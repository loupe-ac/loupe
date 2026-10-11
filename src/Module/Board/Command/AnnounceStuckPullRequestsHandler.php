<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\StuckPullRequestRepository;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * No card changes when the stuck delay of a pull request ends, so this refreshes the cards at that moment.
 * A ready pull request is announced once. A quiet pull request is announced when its delay ended inside the last window.
 * Answers how many cards it refreshed.
 */
final readonly class AnnounceStuckPullRequestsHandler
{
    /** The pull requests announced in one run. */
    public const int BATCH = 200;

    /** How far back a quiet pull request whose delay ended is still announced, in seconds. */
    public const int QUIET_WINDOW_SECONDS = 300;

    public function __construct(
        private StuckPullRequestRepository $stuckPullRequests,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(AnnounceStuckPullRequestsCommand $command): int
    {
        $refreshed = 0;
        $announced = [];
        $now = $this->clock->now();
        $rows = $this->stuckPullRequests->findDue($now, self::BATCH);
        foreach ($rows as $row) {
            $this->events->dispatch(new CardChanged(Uuid::fromString($row['projectId']), Uuid::fromString($row['cardId']), CardChanged::UPDATED, false));
            $announced[$row['pullRequestId']] = $row['readySince'];
            ++$refreshed;
        }
        foreach ($announced as $pullRequestId => $readySince) {
            $this->stuckPullRequests->markAnnounced($pullRequestId, $readySince, $now);
        }

        $after = null;
        do {
            $quiet = $this->stuckPullRequests->findQuietDue($now, self::QUIET_WINDOW_SECONDS, self::BATCH, $after);
            foreach ($quiet as $row) {
                $this->events->dispatch(new CardChanged(Uuid::fromString($row['projectId']), Uuid::fromString($row['cardId']), CardChanged::UPDATED, false));
                $after = $row['cardId'];
                ++$refreshed;
            }
        } while (\count($quiet) === self::BATCH);

        return $refreshed;
    }
}
