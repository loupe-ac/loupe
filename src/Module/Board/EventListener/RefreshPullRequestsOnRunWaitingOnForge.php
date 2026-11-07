<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Entity\Forge;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\PullRequestTracking;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Forge\Service\PullRequestRefreshTarget;
use App\Module\Forge\Service\PullRequestTracker;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A run that ends waiting on the forge has just pushed a head. The stored
 * state still holds the checks of the old head, so the card would show a
 * failure that no longer applies. This reads the pull requests of the card at
 * once, and again after a delay for a forge that moves the head late.
 */
#[AsEventListener]
final readonly class RefreshPullRequestsOnRunWaitingOnForge
{
    private const int LATE_READ_DELAY_MILLISECONDS = 30_000;

    public function __construct(
        private WorkerRunRepository $workerRuns,
        private CardRepository $cards,
        private PullRequestTracking $tracking,
        private PullRequestTracker $tracker,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerRunChanged $event): void
    {
        try {
            $cardIds = [];
            $runs = [];
            foreach ($this->workerRuns->findByIds($event->runIds) as $run) {
                $cardId = $run->cardId();
                if (WorkerRunState::WaitingOnForge === $run->state && null !== $cardId) {
                    $cardIds[$cardId->toRfc4122()] = $cardId;
                    $runs[] = $run;
                }
            }
            if ([] === $runs) {
                return;
            }

            $project = $runs[0]->project;
            $byRepository = [];
            foreach ($this->cards->findByIdsInProject($project, array_values($cardIds)) as $card) {
                foreach ($this->tracking->referencesOf($card) as [$repository, $number]) {
                    $byRepository[$repository][$number] = $number;
                }
            }

            foreach ($byRepository as $repository => $numbers) {
                foreach ([0, self::LATE_READ_DELAY_MILLISECONDS] as $delay) {
                    $this->tracker->refreshAll(
                        $project,
                        Forge::GitHub->value,
                        $repository,
                        array_values(array_map(static fn (int $number): PullRequestRefreshTarget => PullRequestRefreshTarget::number($number, delayMilliseconds: $delay), $numbers)),
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('board.pull_request_refresh_on_run_failed', ['projectId' => (string) $event->projectId, 'exception' => $e]);
        }
    }
}
