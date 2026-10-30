<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Mcp\InboxDoneTool;
use App\Tests\Support\McpTokenScenario;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class InboxDoneToolTest extends KernelTestCase
{
    use AgentResponseScenario;
    use InboxToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private InboxDoneTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        RecordingAuditor::installedIn(self::getContainer());

        $tool = self::getContainer()->get(InboxDoneTool::class);
        self::assertInstanceOf(InboxDoneTool::class, $tool);
        $this->tool = $tool;
    }

    public function test_the_tool_refuses_while_the_flag_is_off(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-done-flag-off'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The inbox is switched off on this instance.');
        ($this->tool)((string) Uuid::v4());
    }

    public function test_a_todo_is_marked_done_through_an_agent(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-done'));
        $todo = $this->askTodo();

        $result = ($this->tool)($todo['itemId']);

        self::assertSame('resolved', $result['state']);
        $item = $this->stored($todo['itemId']);
        self::assertSame(InboxItemState::Resolved, $item->state);
        self::assertSame([InboxCloser::OwnerThroughAgent, null], [$item->closedBy, $item->closeCause]);
    }

    public function test_a_second_done_is_refused_once_the_ask_closed(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-done-twice'));
        $todo = $this->askTodo();
        ($this->tool)($todo['itemId']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The response is final');
        ($this->tool)($todo['itemId']);
    }

    public function test_a_question_is_not_a_todo(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-done-question'));
        $asked = $this->askQuestion('Which column?');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Only a todo can be marked done.');
        ($this->tool)($asked['items'][0]['itemId']);
    }

    public function test_a_worker_session_is_refused_and_the_todo_stays_open(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-done-worker');
        $this->actAsMcpTokenBoundTo($project);
        $todo = $this->askTodo();
        $this->asWorkerSession($project);

        try {
            ($this->tool)($todo['itemId']);
            self::fail('Expected a refusal for a worker session.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('A bridge worker cannot answer an inbox item.', $e->getMessage());
        }

        self::assertSame(InboxItemState::Open, $this->stored($todo['itemId'])->state);
    }

    public function test_an_item_of_another_project_is_not_reachable(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-done-theirs'));
        $theirs = $this->askTodo('Not yours');
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-done-mine'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('not found or not accessible');
        ($this->tool)($theirs['itemId']);
    }
}
