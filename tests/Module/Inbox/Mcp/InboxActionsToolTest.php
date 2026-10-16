<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Command\RunInboxActionHandler;
use App\Module\Inbox\Mcp\InboxActionsTool;
use App\Module\Project\Entity\Project;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class InboxActionsToolTest extends KernelTestCase
{
    use InboxToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private InboxActionsTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(InboxActionsTool::class);
        self::assertInstanceOf(InboxActionsTool::class, $tool);
        $this->tool = $tool;
    }

    public function test_the_tool_refuses_while_the_flag_is_off(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-actions-flag-off'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The inbox is switched off on this instance.');
        ($this->tool)();
    }

    public function test_it_lists_the_eight_actions_with_no_fit_when_no_card_is_given(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-actions-list'));

        $actions = ($this->tool)()['actions'];

        self::assertSame(
            ['resume-run', 'retry-card', 'review', 'open', 'send-agent', 'start-session', 'run-command', 'dismiss'],
            array_column($actions, 'action'),
        );
        self::assertSame([null], array_unique(array_column($actions, 'fitsCard')));
        self::assertNotContains('', array_column($actions, 'effect'));
    }

    public function test_a_card_with_no_wait_item_fits_open_alone_while_no_bridge_can_open_a_session(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-actions-card');
        $card = $this->card($this->em, $project);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame(['open'], $this->fitting((string) $card->id));
    }

    public function test_a_card_with_no_wait_item_fits_open_and_start_session_with_a_session_bridge(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-actions-card-session');
        $card = $this->card($this->em, $project);
        $this->sessionBridge($project);
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame(['open', 'start-session'], $this->fitting((string) $card->id));
    }

    public function test_a_card_with_no_wait_item_does_not_fit_start_session_while_a_session_request_is_live(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-actions-card-live');
        $card = $this->card($this->em, $project);
        $this->sessionBridge($project);
        $this->liveSession($project, $card);
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame(['open'], $this->fitting((string) $card->id));
    }

    public function test_a_card_waiting_for_a_review_fits_review_open_and_dismiss_while_no_bridge_can_open_a_session(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-actions-review');
        $card = $this->card($this->em, $project);
        $this->documentInReview($project, $card);
        $this->reconcileWaits($project, $card);
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame(['review', 'open', 'send-agent', 'dismiss'], $this->fitting((string) $card->id));
    }

    public function test_a_card_waiting_for_a_review_fits_start_session_with_a_session_bridge(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-actions-review-session');
        $card = $this->card($this->em, $project);
        $this->documentInReview($project, $card);
        $this->reconcileWaits($project, $card);
        $this->sessionBridge($project);
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame(['review', 'open', 'send-agent', 'start-session', 'dismiss'], $this->fitting((string) $card->id));
    }

    public function test_a_card_waiting_for_a_review_does_not_fit_start_session_while_a_session_request_is_live(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-actions-review-live');
        $card = $this->card($this->em, $project);
        $this->documentInReview($project, $card);
        $this->reconcileWaits($project, $card);
        $this->sessionBridge($project);
        $this->liveSession($project, $card);
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame(['review', 'open', 'send-agent', 'dismiss'], $this->fitting((string) $card->id));
    }

    public function test_a_card_of_another_project_is_refused(): void
    {
        $this->enableInbox();
        $theirs = $this->makeProject('inbox-actions-theirs');
        $card = $this->card($this->em, $theirs);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-actions-mine'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The card id must name a card of this project.');
        ($this->tool)((string) $card->id);
    }

    public function test_a_malformed_card_id_is_refused(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-actions-bad-id'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('is not a valid card ID');
        ($this->tool)('nope');
    }

    public function test_an_unknown_card_is_refused(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-actions-unknown'));

        $this->expectException(ToolCallException::class);
        ($this->tool)((string) Uuid::v4());
    }

    /** @return list<string> the actions whose fitsCard is true */
    private function fitting(string $cardId): array
    {
        return array_values(array_column(array_filter(($this->tool)($cardId)['actions'], static fn (array $action): bool => true === $action['fitsCard']), 'action'));
    }

    private function sessionBridge(Project $project): void
    {
        $bridge = new Bridge($project->owner, Uuid::v4(), [(string) $project->id], 'b4e39aa7', new \DateTimeImmutable());
        $bridge->capabilities = [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_SESSION];
        $this->em->persist($bridge);
        $this->em->flush();
    }

    private function liveSession(Project $project, Card $card): void
    {
        $this->em->persist(new WorkRequest($project, WorkSubject::CARD, $card->id ?? throw new \LogicException('Stored.'), $card->number, WorkRequest::SESSION_KIND, 'session', RunInboxActionHandler::SESSION_RULE_ID, new \DateTimeImmutable()));
        $this->em->flush();
    }
}
