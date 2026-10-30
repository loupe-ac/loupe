<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Mcp\InboxAnswerTool;
use App\Module\Workflow\Messenger\RunRuleAskAnswer;
use App\Tests\Support\McpTokenScenario;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class InboxAnswerToolTest extends KernelTestCase
{
    use AgentResponseScenario;
    use InboxToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private InboxAnswerTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        RecordingAuditor::installedIn(self::getContainer());

        $tool = self::getContainer()->get(InboxAnswerTool::class);
        self::assertInstanceOf(InboxAnswerTool::class, $tool);
        $this->tool = $tool;
    }

    public function test_the_tool_refuses_while_the_flag_is_off(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-answer-flag-off'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The inbox is switched off on this instance.');
        ($this->tool)((string) Uuid::v4(), [0]);
    }

    public function test_options_answer_the_question_and_mark_the_answer_as_given_through_an_agent(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-answer-options'));
        $asked = $this->askQuestion('Which column?', ['options' => ['a', 'b', 'c'], 'multiple' => true]);

        $result = ($this->tool)($asked['items'][0]['itemId'], [2, 0]);

        self::assertSame('resolved', $result['state']);
        $item = $this->stored($asked['items'][0]['itemId']);
        self::assertSame([0, 2], $item->selectedOptions);
        self::assertNull($item->note);
        self::assertSame(InboxCloser::OwnerThroughAgent, $item->closedBy);
    }

    public function test_free_text_answers_the_question(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-answer-text'));
        $asked = $this->askQuestion('Why?', ['options' => [], 'freeText' => true]);

        ($this->tool)($asked['items'][0]['itemId'], answerText: '  Because  ');

        $item = $this->stored($asked['items'][0]['itemId']);
        self::assertSame('Because', $item->note);
        self::assertSame([], $item->selectedOptions);
        self::assertSame(InboxCloser::OwnerThroughAgent, $item->closedBy);
    }

    public function test_options_and_text_answer_together(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-answer-both'));
        $asked = $this->askQuestion('Which column?', ['freeText' => true]);

        ($this->tool)($asked['items'][0]['itemId'], [1], 'And say why');

        $item = $this->stored($asked['items'][0]['itemId']);
        self::assertSame([1], $item->selectedOptions);
        self::assertSame('And say why', $item->note);
    }

    public function test_a_sent_answer_is_final_while_an_ask_that_holds_the_item_is_still_open(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-answer-change');
        $this->actAsMcpTokenBoundTo($project);
        $item = $this->item($this->em, $project);
        $this->em->flush();
        ($this->tool)((string) $item->id, [0]);

        try {
            ($this->tool)((string) $item->id, [1]);
            self::fail('Expected a refusal for a second answer.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('its response is final', $e->getMessage());
        }

        self::assertSame([0], $this->stored((string) $item->id)->selectedOptions);
    }

    public function test_an_answer_is_final_once_the_ask_that_held_the_item_closed(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-answer-final'));
        $asked = $this->askQuestion('Which column?');
        ($this->tool)($asked['items'][0]['itemId'], [0]);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('its response is final');
        ($this->tool)($asked['items'][0]['itemId'], [1]);
    }

    public function test_the_answer_to_a_workflow_question_runs_its_option_and_is_final(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-answer-workflow');
        $this->actAsMcpTokenBoundTo($project);
        $item = new InboxItem(project: $project, number: 1, kind: InboxItemKind::Workflow, title: 'Child 3 has no design', blocking: true, origin: InboxAskOrigin::Loupe, options: ['Link', 'Design', 'Detach']);
        $this->em->persist($item);
        $ask = new InboxAsk(project: $project, sessionId: null, bridgeId: null);
        $ask->items->add(new InboxAskItem($ask, $item));
        $this->em->persist($ask);
        $this->em->flush();

        ($this->tool)((string) $item->id, [1]);

        $transport = self::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $sent = array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()),
            static fn (object $message): bool => $message instanceof RunRuleAskAnswer,
        ));
        self::assertCount(1, $sent);
        self::assertSame(InboxItemState::Resolved, $this->stored((string) $item->id)->state);

        try {
            ($this->tool)((string) $item->id, [2]);
            self::fail('Expected a refusal for a second answer.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('its response is final', $e->getMessage());
        }
    }

    public function test_a_todo_is_not_a_question(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-answer-todo'));
        $todo = $this->askTodo();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Only a question takes an answer.');
        ($this->tool)($todo['itemId'], [0]);
    }

    public function test_an_unknown_option_is_refused_and_leaves_the_item_open(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-answer-unknown'));
        $asked = $this->askQuestion('Which column?');

        try {
            ($this->tool)($asked['items'][0]['itemId'], [5]);
            self::fail('Expected a refusal for an unknown option.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('Each selected option must be an index', $e->getMessage());
        }

        self::assertSame(InboxItemState::Open, $this->stored($asked['items'][0]['itemId'])->state);
    }

    public function test_a_worker_session_is_refused_and_the_item_stays_open(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-answer-worker');
        $this->actAsMcpTokenBoundTo($project);
        $asked = $this->askQuestion('Which column?');
        $this->asWorkerSession($project);

        try {
            ($this->tool)($asked['items'][0]['itemId'], [0]);
            self::fail('Expected a refusal for a worker session.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('A bridge worker cannot answer an inbox item.', $e->getMessage());
        }

        $item = $this->stored($asked['items'][0]['itemId']);
        self::assertSame(InboxItemState::Open, $item->state);
        self::assertNull($item->closedBy);
    }

    public function test_an_item_of_another_project_is_not_reachable(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-answer-theirs'));
        $theirs = $this->askQuestion('Not yours');
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-answer-mine'));

        try {
            ($this->tool)($theirs['items'][0]['itemId'], [0]);
            self::fail('Expected a refusal for an item of another project.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('not found or not accessible', $e->getMessage());
        }

        self::assertSame(InboxItemState::Open, $this->stored($theirs['items'][0]['itemId'])->state);
    }
}
