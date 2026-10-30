<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Module\Board\Mcp\AgentRunCause;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCloseCause;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
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

final class InboxWithdrawToolTest extends KernelTestCase
{
    use InboxToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private InboxWithdrawTool $tool;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        // Installed before the tool is built, so the voter it reaches records here.
        $this->audit = RecordingAuditor::installedIn(self::getContainer());

        $tool = self::getContainer()->get(InboxWithdrawTool::class);
        self::assertInstanceOf(InboxWithdrawTool::class, $tool);
        $this->tool = $tool;
    }

    public function test_the_tool_refuses_while_the_flag_is_off(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-withdraw-flag-off'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The inbox is switched off on this instance.');
        ($this->tool)((string) Uuid::v4(), (string) Uuid::v4(), 'Settled');
    }

    public function test_an_open_item_closes_as_withdrawn_with_the_reason(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-withdraw'));
        $session = (string) Uuid::v4();
        $asked = $this->askQuestion('Which column?', sessionId: $session);

        $result = ($this->tool)($asked['items'][0]['itemId'], $session, '  The pull request merged  ');

        self::assertSame('cancelled', $result['state']);
        self::assertNotNull($result['closedAt']);

        $this->em->clear();
        $item = $this->em->find(InboxItem::class, Uuid::fromString($asked['items'][0]['itemId']));
        self::assertInstanceOf(InboxItem::class, $item);
        self::assertSame(InboxItemState::Cancelled, $item->state);
        self::assertSame('The pull request merged', $item->note);
        self::assertSame([InboxCloser::Agent, InboxCloseCause::Withdrawn], [$item->closedBy, $item->closeCause]);
        self::assertNotNull($item->closedAt);
    }

    public function test_a_closed_item_is_refused(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-withdraw-twice'));
        $session = (string) Uuid::v4();
        $asked = $this->askQuestion('Which column?', sessionId: $session);
        ($this->tool)($asked['items'][0]['itemId'], $session, 'First');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('itemId: The item is already closed.');
        ($this->tool)($asked['items'][0]['itemId'], $session, 'Second');
    }

    public function test_a_wait_item_is_refused(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-withdraw-wait');
        $this->actAsMcpTokenBoundTo($project);
        $item = new InboxItem(project: $project, number: 1, kind: InboxItemKind::Wait, title: '#1 Ship it', blocking: true, origin: InboxAskOrigin::Loupe);
        $this->em->persist($item);
        $this->em->flush();

        try {
            ($this->tool)((string) $item->id, (string) Uuid::v4(), 'Not needed');
            self::fail('Expected a refusal for a wait item.');
        } catch (ToolCallException $e) {
            self::assertSame('itemId: Loupe opened this item for a card that waits for a person, so no agent can withdraw it. It closes when the card no longer waits.', $e->getMessage());
        }

        $this->em->clear();
        self::assertSame(InboxItemState::Open, $this->em->find(InboxItem::class, $item->id)?->state);
    }

    public function test_a_blank_reason_is_refused(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-withdraw-blank'));
        $session = (string) Uuid::v4();
        $asked = $this->askQuestion('Which column?', sessionId: $session);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('reason: Pass the reason you no longer need the item.');
        ($this->tool)($asked['items'][0]['itemId'], $session, '  ');
    }

    public function test_a_reason_over_the_limit_is_refused(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-withdraw-long'));
        $session = (string) Uuid::v4();
        $asked = $this->askQuestion('Which column?', sessionId: $session);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('reason: A withdraw reason must be at most 2000 characters.');
        ($this->tool)($asked['items'][0]['itemId'], $session, str_repeat('a', 2001));
    }

    /** The schema measures the reason as sent, so the handler does too, spaces included. */
    public function test_a_reason_over_the_limit_only_with_its_spaces_is_refused(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-withdraw-spaces'));
        $session = (string) Uuid::v4();
        $asked = $this->askQuestion('Which column?', sessionId: $session);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('reason: A withdraw reason must be at most 2000 characters.');
        ($this->tool)($asked['items'][0]['itemId'], $session, str_repeat('a', 2000).' ');
    }

    public function test_another_session_cannot_withdraw_the_item(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-withdraw-other-session'));
        $asked = $this->askQuestion('Which column?');

        try {
            ($this->tool)($asked['items'][0]['itemId'], (string) Uuid::v4(), 'Not mine');
            self::fail('Expected a refusal for a session that did not ask.');
        } catch (ToolCallException $e) {
            self::assertSame('sessionId: Only the session that asked first can withdraw this item. Pass your own session id.', $e->getMessage());
        }

        self::assertSame(InboxItemState::Open, $this->em->find(InboxItem::class, Uuid::fromString($asked['items'][0]['itemId']))?->state);
    }

    public function test_the_session_of_a_later_ask_that_joined_the_item_cannot_withdraw_it(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-withdraw-joined'));
        $asked = $this->askQuestion('Which column?');
        $join = self::getContainer()->get(InboxJoinTool::class);
        self::assertInstanceOf(InboxJoinTool::class, $join);
        $joiner = (string) Uuid::v4();
        $join($asked['items'][0]['itemId'], $joiner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('sessionId: Only the session that asked first can withdraw this item.');
        ($this->tool)($asked['items'][0]['itemId'], $joiner, 'Not mine');
    }

    public function test_a_session_header_that_names_another_session_is_refused_even_for_the_asking_session(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-withdraw-header'));
        $session = (string) Uuid::v4();
        $asked = $this->askQuestion('Which column?', sessionId: $session);
        $this->sendFromSession((string) Uuid::v4());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('sessionId: Pass your own session id. This call comes from another session.');
        ($this->tool)($asked['items'][0]['itemId'], $session, 'Mine');
    }

    public function test_a_session_header_that_names_the_asking_session_may_withdraw(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-withdraw-own-header'));
        $session = (string) Uuid::v4();
        $asked = $this->askQuestion('Which column?', sessionId: $session);
        $this->sendFromSession($session);

        self::assertSame('cancelled', ($this->tool)($asked['items'][0]['itemId'], $session, 'Mine')['state']);
    }

    public function test_an_item_in_another_project_is_not_reachable_and_the_refusal_is_audited(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-withdraw-theirs'));
        $theirs = $this->askQuestion('Not yours');

        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-withdraw-mine'));

        try {
            ($this->tool)($theirs['items'][0]['itemId'], (string) Uuid::v4(), 'Mine now');
            self::fail('Expected a refusal for an item of another project.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('not found or not accessible', $e->getMessage());
        }

        $record = $this->audit->record('inbox.mcp_access_denied');
        self::assertSame(McpBoundProjectVoter::INBOX_ITEM_WRITE, $record->context['attribute']);
        self::assertSame($theirs['items'][0]['itemId'], $record->context['subjectId']);
    }

    /** Sends the next tool call with the session header, as a client that sets it does. */
    private function sendFromSession(string $sessionId): void
    {
        $request = Request::create('/mcp', Request::METHOD_POST);
        $request->headers->set(AgentRunCause::SESSION_HEADER, $sessionId);
        $requests = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requests);
        $requests->push($request);
    }
}
