<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunChangeKind;
use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

/**
 * Tells the open worker run pages of a project to reload. The message names
 * the runs and the cards that changed, and never their content, because what a
 * page shows depends on who is looking at it. A message with no ids names no
 * single run, and a page reads it as a change to any run. It also tells the open
 * boards when a run of a card changes, which can change its warning.
 */
final readonly class WorkerRunChangedPublisher
{
    public const string TYPE = 'worker_run.changed';

    public const string OUTPUT_TYPE = 'worker_run.output';

    /** A change to more runs than this names none, so the message stays small. */
    public const int MAX_NAMED_RUNS = 50;

    public const string CARD_WARNING_CHANGED = 'worker_run.card_warning_changed';

    public function __construct(
        private ProjectTopicBuilder $topics,
        private LiveUpdatePublisher $publisher,
        private SidebarCountsSignal $sidebarCounts,
    ) {
    }

    /**
     * Call it after the change commits, so a rollback signals nothing.
     *
     * @param list<WorkerRun> $runs    the runs that changed; none when the change names no single run
     * @param list<Uuid>      $cardIds the cards that changed besides the cards of the runs
     */
    public function runsChanged(Project $project, WorkerRunChangeKind $kind, array $runs = [], array $cardIds = []): void
    {
        $projectId = $project->id ?? throw new \LogicException('Project has no id.');
        $runIds = [];
        $cards = [];
        foreach ($runs as $run) {
            $runIds[(string) $run->id] = true;
            if (null !== $run->cardId()) {
                $cards[(string) $run->cardId()] = true;
            }
        }
        foreach ($cardIds as $cardId) {
            $cards[(string) $cardId] = true;
        }
        // A change that names too many runs, or only cards, reads as a change to any run.
        $named = [] !== $runIds && \count($runIds) <= self::MAX_NAMED_RUNS;
        $this->publisher->queue($this->topics->forWorkerRuns($projectId), [
            'type' => self::TYPE,
            'kind' => $kind->value,
            'runIds' => $named ? array_keys($runIds) : [],
            'cardIds' => $named || [] === $runIds ? array_keys($cards) : [],
        ]);
        $this->sidebarCounts->mark($project);
    }

    /** Tells the open run pages that the output log of one run grew to $length bytes. Call it after the change commits. */
    public function outputAppended(Project $project, Uuid $runId, int $length): void
    {
        $projectId = $project->id ?? throw new \LogicException('Project has no id.');
        $this->publisher->queue($this->topics->forWorkerRuns($projectId), ['type' => self::OUTPUT_TYPE, 'runId' => (string) $runId, 'length' => $length]);
    }

    /** Tells the open boards to place the card again. Call it after the change commits. */
    public function cardWarningChanged(Uuid $projectId, Uuid $cardId): void
    {
        $this->publisher->queue($this->topics->forBoard($projectId), ['type' => self::CARD_WARNING_CHANGED, 'cardId' => (string) $cardId]);
    }
}
