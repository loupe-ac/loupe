<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Inbox\Mcp\InboxDeclineTool;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Tests\Support\McpTokenScenario;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class InboxDeclineToolTest extends KernelTestCase
{
    use AgentResponseScenario;
    use InboxToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private InboxDeclineTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        RecordingAuditor::installedIn(self::getContainer());

        $tool = self::getContainer()->get(InboxDeclineTool::class);
        self::assertInstanceOf(InboxDeclineTool::class, $tool);
        $this->tool = $tool;
    }

    public function test_the_tool_refuses_while_the_flag_is_off(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-decline-flag-off'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The inbox is switched off on this instance.');
        ($this->tool)((string) Uuid::v4());
    }

    public function test_a_question_is_declined_with_the_note_and_marked_as_given_through_an_agent(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-decline-question'));
        $asked = $this->askQuestion('Which column?');

        $result = ($this->tool)($asked['items'][0]['itemId'], '  Not now  ');

        self::assertSame('declined', $result['state']);
        $item = $this->stored($asked['items'][0]['itemId']);
        self::assertSame('Not now', $item->note);
        self::assertSame(InboxCloser::OwnerThroughAgent, $item->closedBy);
    }

    public function test_a_todo_is_declined(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-decline-todo'));
        $todo = $this->askTodo();

        ($this->tool)($todo['itemId']);

        self::assertSame(InboxItemState::Declined, $this->stored($todo['itemId'])->state);
    }

    public function test_a_wait_item_is_dismissed(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-decline-wait');
        $this->actAsMcpTokenBoundTo($project);
        $card = $this->card($this->em, $project);
        $item = new InboxItem(project: $project, number: 1, kind: InboxItemKind::Wait, title: '#1 Ship it', blocking: true, origin: InboxAskOrigin::Loupe);
        $this->em->persist($item);
        $this->em->persist(new InboxCardWatch($item, $card->id ?? throw new \LogicException('Stored.'), $card->number));
        $this->em->flush();

        $result = ($this->tool)((string) $item->id);

        self::assertSame('declined', $result['state']);
        $watches = self::getContainer()->get(InboxCardWatchRepository::class);
        self::assertInstanceOf(InboxCardWatchRepository::class, $watches);
        $watch = $watches->findOneForItem($this->stored((string) $item->id));
        self::assertNotNull($watch?->dismissedAt);
        self::assertSame(InboxCloser::OwnerThroughAgent, $this->stored((string) $item->id)->closedBy);
    }

    public function test_an_automatic_review_item_is_dismissed(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-decline-auto-review');
        $this->actAsMcpTokenBoundTo($project);
        $card = $this->card($this->em, $project);
        $item = new InboxItem(project: $project, number: 1, kind: InboxItemKind::Review, title: 'Review the design', blocking: true, origin: InboxAskOrigin::Loupe);
        $this->em->persist($item);
        $this->em->persist(new InboxCardWatch($item, $card->id ?? throw new \LogicException('Stored.'), $card->number));
        $this->em->flush();

        $result = ($this->tool)((string) $item->id);

        self::assertSame('declined', $result['state']);
        self::assertSame(InboxItemState::Declined, $this->stored((string) $item->id)->state);
    }

    public function test_a_review_is_refused_before_the_handler_and_stays_open(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-decline-review');
        $this->actAsMcpTokenBoundTo($project);
        $document = $this->document($this->em, $project);
        $document->addVersion('# One', '<h1>One</h1>');
        $item = new InboxItem(project: $project, number: 1, kind: InboxItemKind::Review, title: 'Review the design', blocking: true, origin: InboxAskOrigin::Agent);
        $this->em->persist($item);
        $this->em->persist(new InboxReview($item, $document));
        $ask = new InboxAsk(project: $project, sessionId: Uuid::v4(), bridgeId: null);
        $ask->items->add(new InboxAskItem($ask, $item));
        $this->em->persist($ask);
        $this->em->flush();

        try {
            ($this->tool)((string) $item->id, 'No');
            self::fail('Expected a refusal for a review.');
        } catch (ToolCallException $e) {
            self::assertSame('itemId: A review verdict stays on the inbox page. Ask the owner to give it there, or call document_verdict_submit when the owner gave it in the chat.', $e->getMessage());
        }

        self::assertSame(InboxItemState::Open, $this->stored((string) $item->id)->state);
    }

    public function test_a_workflow_question_cannot_be_declined(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-decline-workflow');
        $this->actAsMcpTokenBoundTo($project);
        $item = new InboxItem(project: $project, number: 1, kind: InboxItemKind::Workflow, title: 'Child 3 has no design', blocking: true, origin: InboxAskOrigin::Loupe, options: ['Link', 'Design']);
        $this->em->persist($item);
        $this->em->flush();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('A workflow question cannot be declined. Answer it with inbox_answer.');
        ($this->tool)((string) $item->id);
    }

    public function test_a_worker_session_is_refused_and_the_item_stays_open(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-decline-worker');
        $this->actAsMcpTokenBoundTo($project);
        $asked = $this->askQuestion('Which column?');
        $this->asWorkerSession($project);

        try {
            ($this->tool)($asked['items'][0]['itemId']);
            self::fail('Expected a refusal for a worker session.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('A bridge worker cannot answer an inbox item.', $e->getMessage());
        }

        self::assertSame(InboxItemState::Open, $this->stored($asked['items'][0]['itemId'])->state);
    }

    public function test_an_item_of_another_project_is_not_reachable(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-decline-theirs'));
        $theirs = $this->askQuestion('Not yours');
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-decline-mine'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('not found or not accessible');
        ($this->tool)($theirs['items'][0]['itemId']);
    }
}
