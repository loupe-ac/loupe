<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\Support\CallsAsWorkerRun;
use Symfony\Component\Uid\Uuid;

/**
 * KernelTestCase helper for the tools that answer for the owner. Requires an
 * `$em` property and the InboxToolScenario trait on the using class.
 */
trait AgentResponseScenario
{
    use CallsAsWorkerRun;

    /** Sends the next tool call with the run credential of a bridge worker run of the project. */
    private function asWorkerSession(Project $project): void
    {
        $bridgeId = Uuid::v4();
        $runKey = Uuid::v4();
        $sessionId = Uuid::v4();
        $this->em->persist(new WorkerRun(
            project: $project,
            bridgeId: $bridgeId,
            subjectType: WorkSubject::CARD,
            subjectId: Uuid::v4(),
            cardNumber: 1,
            workKind: 'implement',
            state: WorkerRunState::Running,
            runKey: $runKey,
            sessionId: $sessionId,
        ));
        $this->em->flush();

        $this->callAsWorkerRun($bridgeId, $runKey, $sessionId, $project->id);
    }

    /** @return array{itemId: string, state: string} */
    private function askTodo(string $title = 'Publish the notes'): array
    {
        return ($this->askTool())(sessionId: (string) Uuid::v4(), items: [['kind' => 'todo', 'title' => $title]])['items'][0];
    }

    private function stored(string $itemId): InboxItem
    {
        $this->em->clear();
        $item = $this->em->find(InboxItem::class, Uuid::fromString($itemId));
        self::assertInstanceOf(InboxItem::class, $item);

        return $item;
    }
}
