<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Module\Board\Mcp\AgentRunCause;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Mcp\InboxJoinTool;
use App\Module\Inbox\Mcp\InboxWithdrawTool;
use App\Security\McpBoundProjectVoter;
use App\Tests\Support\McpTokenScenario;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

final class InboxJoinToolTest extends KernelTestCase
{
    use InboxToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private InboxJoinTool $tool;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        // Installed before the tool is built, so the voter it reaches records here.
        $this->audit = RecordingAuditor::installedIn(self::getContainer());

        $tool = self::getContainer()->get(InboxJoinTool::class);
        self::assertInstanceOf(InboxJoinTool::class, $tool);
        $this->tool = $tool;
    }

    public function test_the_tool_refuses_while_the_flag_is_off(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-join-flag-off'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The inbox is switched off on this instance.');
        ($this->tool)((string) Uuid::v4(), (string) Uuid::v4());
    }

    public function test_a_session_with_no_ask_opens_one_that_holds_the_item(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-join-open'));
        $asked = $this->askQuestion('Which column?');
        $sessionId = (string) Uuid::v4();
        $bridgeId = (string) Uuid::v4();

        $result = ($this->tool)($asked['items'][0]['itemId'], $sessionId, $bridgeId);

        self::assertFalse($result['extended']);
        self::assertFalse($result['closed']);
        self::assertNotSame($asked['askId'], $result['askId']);
        self::assertSame($asked['items'][0]['itemId'], $result['items'][0]['itemId']);

        $this->em->clear();
        $ask = $this->em->find(InboxAsk::class, Uuid::fromString($result['askId']));
        self::assertInstanceOf(InboxAsk::class, $ask);
        self::assertSame($sessionId, (string) $ask->sessionId);
        self::assertSame($bridgeId, (string) $ask->bridgeId);
        self::assertCount(1, $ask->items);
    }

    public function test_an_agent_waits_on_a_wait_item_until_loupe_closes_it(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-join-wait');
        $card = $this->card($this->em, $project, 3);
        $this->em->flush();
        $document = $this->documentInReview($project, $card);
        $watch = $this->reconcileWaits($project, $card);
        $this->actAsMcpTokenBoundTo($project);

        $result = ($this->tool)((string) $watch->item->id, (string) Uuid::v4());

        self::assertFalse($result['closed']);
        self::assertSame('review', $result['items'][0]['kind']);
        self::assertSame('loupe', $result['items'][0]['origin']);

        $this->storeInbox($this->em, $card);
        $this->reconcileWaits($project, $card);
        $this->em->clear();

        $ask = $this->em->find(InboxAsk::class, Uuid::fromString($result['askId']));
        self::assertInstanceOf(InboxAsk::class, $ask);
        self::assertNotNull($ask->closedAt);
    }

    public function test_the_item_joins_the_session_s_open_ask_once(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-join-extend'));
        $sessionId = (string) Uuid::v4();
        $mine = $this->askQuestion('Mine', sessionId: $sessionId);
        $theirs = $this->askQuestion('Theirs');

        $result = ($this->tool)($theirs['items'][0]['itemId'], $sessionId);
        $again = ($this->tool)($theirs['items'][0]['itemId'], $sessionId);

        self::assertTrue($result['extended']);
        self::assertSame($mine['askId'], $result['askId']);
        self::assertSame($mine['askId'], $again['askId']);

        $this->em->clear();
        $ask = $this->em->find(InboxAsk::class, Uuid::fromString($mine['askId']));
        self::assertInstanceOf(InboxAsk::class, $ask);
        self::assertCount(2, $ask->items);
    }

    public function test_a_new_ask_whose_item_does_not_block_closes_at_once(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-join-no-blocking'));
        $todo = ($this->askTool())(sessionId: (string) Uuid::v4(), items: [['kind' => 'todo', 'title' => 'Review pull request 482']]);

        $result = ($this->tool)($todo['items'][0]['itemId'], (string) Uuid::v4());

        self::assertTrue($result['closed']);
        self::assertSame('open', $result['items'][0]['state']);
    }

    public function test_a_closed_item_is_refused(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-join-closed'));
        $session = (string) Uuid::v4();
        $asked = $this->askQuestion('Which column?', sessionId: $session);
        $withdraw = self::getContainer()->get(InboxWithdrawTool::class);
        self::assertInstanceOf(InboxWithdrawTool::class, $withdraw);
        $withdraw($asked['items'][0]['itemId'], $session, 'Settled');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('itemId: The item is already closed.');
        ($this->tool)($asked['items'][0]['itemId'], (string) Uuid::v4());
    }

    public function test_an_item_in_another_project_is_not_reachable_and_the_refusal_is_audited(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-join-theirs'));
        $theirs = $this->askQuestion('Not yours');

        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-join-mine'));

        try {
            ($this->tool)($theirs['items'][0]['itemId'], (string) Uuid::v4());
            self::fail('Expected a refusal for an item of another project.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('not found or not accessible', $e->getMessage());
        }

        $record = $this->audit->record('inbox.mcp_access_denied');
        self::assertSame(McpBoundProjectVoter::INBOX_ITEM_WRITE, $record->context['attribute']);
        self::assertSame($theirs['items'][0]['itemId'], $record->context['subjectId']);
    }

    public function test_a_different_bridge_for_the_session_s_open_ask_is_refused(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-join-bridge'));
        $sessionId = (string) Uuid::v4();
        ($this->askTool())(sessionId: $sessionId, items: [['kind' => 'question', 'title' => 'Mine', 'freeText' => true]], bridgeId: (string) Uuid::v4());
        $theirs = $this->askQuestion('Theirs');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('bridgeId: This session\'s open ask already names another bridge.');
        ($this->tool)($theirs['items'][0]['itemId'], $sessionId, (string) Uuid::v4());
    }

    public function test_a_wait_item_is_refused(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-join-wait');
        $this->actAsMcpTokenBoundTo($project);

        $this->assertNotJoinable(new InboxItem(project: $project, number: 1, kind: InboxItemKind::Wait, title: '#1 Ship it', blocking: true, origin: InboxAskOrigin::Loupe));
    }

    public function test_a_notice_is_refused(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-join-notice');
        $this->actAsMcpTokenBoundTo($project);

        $this->assertNotJoinable(new InboxItem(project: $project, number: 1, kind: InboxItemKind::Notice, title: 'The bridge is offline', blocking: false, origin: InboxAskOrigin::Loupe));
    }

    public function test_a_workflow_question_is_refused(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-join-workflow');
        $this->actAsMcpTokenBoundTo($project);

        $this->assertNotJoinable(new InboxItem(project: $project, number: 1, kind: InboxItemKind::Workflow, title: 'Which column?', blocking: true, origin: InboxAskOrigin::Loupe, options: ['next', 'done']));
    }

    public function test_a_session_header_that_names_another_session_is_refused(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-join-header'));
        $asked = $this->askQuestion('Which column?');
        $this->sendFromSession((string) Uuid::v4());

        try {
            ($this->tool)($asked['items'][0]['itemId'], (string) Uuid::v4());
            self::fail('Expected a refusal for a session id that is not the caller\'s.');
        } catch (ToolCallException $e) {
            self::assertSame('sessionId: Pass your own session id. This call comes from another session.', $e->getMessage());
        }
    }

    public function test_a_session_header_that_names_the_session_argument_may_join(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-join-own-header'));
        $asked = $this->askQuestion('Which column?');
        $session = (string) Uuid::v4();
        $this->sendFromSession($session);

        self::assertFalse(($this->tool)($asked['items'][0]['itemId'], $session)['closed']);
    }

    private function assertNotJoinable(InboxItem $item): void
    {
        $this->em->persist($item);
        $this->em->flush();

        try {
            ($this->tool)((string) $item->id, (string) Uuid::v4());
            self::fail('Expected a refusal for an item that Loupe opened.');
        } catch (ToolCallException $e) {
            self::assertStringStartsWith('itemId: Loupe opened this item', $e->getMessage());
        }
    }

    private function sendFromSession(string $sessionId): void
    {
        $request = Request::create('/mcp', Request::METHOD_POST);
        $request->headers->set(AgentRunCause::SESSION_HEADER, $sessionId);
        $requests = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requests);
        $requests->push($request);
    }
}
