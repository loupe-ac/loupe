<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Mercure\CountChangeGate;
use App\Mercure\LiveUpdatePublisher;
use App\Mercure\UserTopicBuilder;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Project\Entity\Project;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Signals the inbox pills of the sidebar only when the open rows of a project
 * changed. The open total counts every project of the owner, so its version
 * belongs to the owner and moves whenever one project signals.
 */
final class InboxCountsSignal implements ResetInterface
{
    public const string TYPE = 'inbox.sidebar_count_changed';

    /** @var array<string, array{project: Project, ownerId: Uuid}> */
    private array $marked = [];

    public function __construct(
        private readonly CountChangeGate $gate,
        private readonly InboxItemRepository $inboxItems,
        private readonly UserTopicBuilder $topics,
        private readonly LiveUpdatePublisher $publisher,
    ) {
    }

    public static function ownerVersionKey(Uuid $ownerId): string
    {
        return 'inbox-sidebar-owner.'.$ownerId->toRfc4122();
    }

    /** The owner is read now, because a rolled back change leaves the EntityManager closed. */
    public function mark(Project $project): void
    {
        $projectId = $project->id ?? throw new \LogicException('Project has no id.');
        $this->marked[$projectId->toRfc4122()] = [
            'project' => $project,
            'ownerId' => $project->owner->id ?? throw new \LogicException('Project owner has no id.'),
        ];
    }

    #[AsEventListener(WorkerMessageHandledEvent::class, priority: 16)]
    #[AsEventListener(WorkerMessageFailedEvent::class, priority: -8)]
    #[AsEventListener(KernelEvents::TERMINATE, priority: 16)]
    #[AsEventListener(ConsoleEvents::TERMINATE, priority: 16)]
    public function check(): void
    {
        $marked = $this->marked;
        $this->marked = [];

        foreach ($marked as $projectId => ['project' => $project, 'ownerId' => $ownerId]) {
            $changed = $this->gate->changed('inbox-sidebar.'.$projectId, fn (): string => (string) $this->inboxItems->countOpenRowsByProject($project));
            if (!$changed) {
                continue;
            }

            $this->gate->replaceVersion(self::ownerVersionKey($ownerId));
            $this->publisher->queue($this->topics->forInbox($ownerId), ['type' => self::TYPE, 'projectId' => $projectId]);
        }
    }

    #[\Override]
    public function reset(): void
    {
        $this->marked = [];
    }
}
