<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Module\Board\Mcp\AgentRunCause;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Project\Entity\Project;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * KernelTestCase helper for the tools that answer for the owner. Requires an
 * `$em` property and the InboxToolScenario trait on the using class.
 */
trait AgentResponseScenario
{
    /** Sends the next tool call from a session that a bridge worker run of the project owns. */
    private function asWorkerSession(Project $project): void
    {
        $sessionId = Uuid::v4();
        $this->em->persist(new WorkerRun(
            project: $project,
            bridgeId: Uuid::v4(),
            subjectType: WorkSubject::CARD,
            subjectId: Uuid::v4(),
            cardNumber: 1,
            workKind: 'implement',
            state: WorkerRunState::Running,
            sessionId: $sessionId,
        ));
        $this->em->flush();

        $request = Request::create('/mcp', Request::METHOD_POST);
        $request->headers->set(AgentRunCause::SESSION_HEADER, (string) $sessionId);
        $requests = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requests);
        $requests->push($request);
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
