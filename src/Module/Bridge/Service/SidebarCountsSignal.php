<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Mercure\CountChangeGate;
use App\Mercure\LiveUpdatePublisher;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Project\Entity\Project;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Signals the sidebar pills of a project only when the worker counts they show
 * changed. A worker event marks the project, and the check runs after the
 * change committed, ahead of the publish of the live updates.
 */
final class SidebarCountsSignal implements ResetInterface
{
    public const string TYPE = 'sidebar.counts_changed';

    /** @var array<string, Project> */
    private array $marked = [];

    public function __construct(
        private readonly CountChangeGate $gate,
        private readonly AgentsFiguresReader $figures,
        private readonly WorkerRunRepository $workerRuns,
        private readonly ProjectTopicBuilder $topics,
        private readonly LiveUpdatePublisher $publisher,
    ) {
    }

    public static function versionKey(Project $project): string
    {
        return 'sidebar-counts.'.($project->id ?? throw new \LogicException('Project has no id.'))->toRfc4122();
    }

    public function mark(Project $project): void
    {
        $this->marked[($project->id ?? throw new \LogicException('Project has no id.'))->toRfc4122()] = $project;
    }

    #[AsEventListener(WorkerMessageHandledEvent::class, priority: 16)]
    #[AsEventListener(WorkerMessageFailedEvent::class, priority: -8)]
    #[AsEventListener(KernelEvents::TERMINATE, priority: 16)]
    #[AsEventListener(ConsoleEvents::TERMINATE, priority: 16)]
    public function check(): void
    {
        $marked = $this->marked;
        $this->marked = [];

        foreach ($marked as $project) {
            $projectId = $project->id ?? throw new \LogicException('Project has no id.');
            $changed = $this->gate->changed(self::versionKey($project), fn (): string => $this->fingerprint($project));
            if ($changed) {
                $this->publisher->queue($this->topics->forWorkerRuns($projectId), ['type' => self::TYPE, 'projectId' => $projectId->toRfc4122()]);
            }
        }
    }

    #[\Override]
    public function reset(): void
    {
        $this->marked = [];
    }

    /** The busy and maximum workers and the open runs, the figures of the Agents and Activity pills. */
    private function fingerprint(Project $project): string
    {
        $figures = $this->figures->forProject($project);

        return (null === $figures ? '-' : $figures->busy.'/'.$figures->limit).'|'.$this->workerRuns->countOpenOfProject($project);
    }
}
