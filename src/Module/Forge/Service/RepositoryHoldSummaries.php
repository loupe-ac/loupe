<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Mercure\LiveUpdatePublisher;
use App\Module\Forge\Entity\ForgeRepositoryCheck;
use App\Module\Forge\Event\ForgeRepositoryCheckChanged;
use App\Module\Forge\Repository\ForgeRepositoryCheckRepository;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The one writer of the stored checks. A write runs under a FOR UPDATE lock on
 * the project row, which conflicts with the FOR KEY SHARE lock of each hold
 * read, so a claim either commits before the write or reads its result.
 */
final readonly class RepositoryHoldSummaries
{
    public const string SIGNAL_TYPE = 'forge.repository_check_changed';

    public function __construct(
        private ForgeRepositoryCheckRepository $forgeRepositoryChecks,
        private ProjectRepository $projects,
        private EntityManagerInterface $em,
        private EventDispatcherInterface $events,
        private LiveUpdatePublisher $publisher,
        private RepositoryCheckTopics $topics,
    ) {
    }

    /**
     * Runs $write in a transaction under the project lock and flushes it. After
     * the commit it dispatches the event when the summary changed, and queues a
     * signal for each repository that $write named.
     *
     * @template T
     *
     * @param callable(RepositoryChange): T $write
     *
     * @return T
     */
    public function change(Project $project, callable $write): mixed
    {
        $change = new RepositoryChange();
        $changed = false;

        $result = $this->em->wrapInTransaction(function () use ($project, $write, $change, &$changed): mixed {
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);
            $before = $this->current($project);

            $result = $write($change);
            $this->em->flush();

            $changed = !$before->equals($this->current($project));

            return $result;
        });

        $projectId = $project->id ?? throw new \LogicException('A persisted project has an id.');
        foreach ($change->signals() as $repositoryId) {
            $this->signal($projectId, $repositoryId);
        }
        if ($changed) {
            $this->events->dispatch(new ForgeRepositoryCheckChanged($projectId));
        }

        return $result;
    }

    /** Queues the live signal of one repository. Call it after the change commits. */
    public function signal(Uuid $projectId, Uuid $repositoryId): void
    {
        $this->publisher->queue($this->topics->forProject($projectId), [
            'type' => self::SIGNAL_TYPE,
            'repositoryId' => (string) $repositoryId,
        ]);
    }

    /** For a caller that holds the project row lock already. */
    public function current(Project $project): RepositoryHoldSummary
    {
        $blocking = [];
        foreach ($this->forgeRepositoryChecks->itemsByRepositoryOfProject($project) as $repositoryId => $items) {
            $count = ForgeRepositoryCheck::countBlockingGaps($items);
            if ($count > 0) {
                $blocking[$repositoryId] = $count;
            }
        }

        return new RepositoryHoldSummary($blocking);
    }

    /** For a reader that holds no lock. It waits for a write that runs, and blocks one until the transaction ends. */
    public function currentUnderKeyShare(Project $project): RepositoryHoldSummary
    {
        $this->projects->lockKeyShare($project);

        return $this->current($project);
    }
}
