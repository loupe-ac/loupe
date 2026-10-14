<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Module\Inbox\Mcp\InboxActionsTool;
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

    public function test_it_lists_the_seven_actions_with_no_fit_when_no_card_is_given(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-actions-list'));

        $actions = ($this->tool)()['actions'];

        self::assertSame(
            ['resume-run', 'retry-card', 'review', 'open', 'send-agent', 'start-session', 'dismiss'],
            array_column($actions, 'action'),
        );
        self::assertSame([null], array_unique(array_column($actions, 'fitsCard')));
        self::assertNotContains('', array_column($actions, 'effect'));
    }

    public function test_a_card_with_no_wait_item_fits_open_and_start_session(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-actions-card');
        $card = $this->card($this->em, $project);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        $actions = ($this->tool)((string) $card->id)['actions'];

        self::assertSame(['open', 'start-session'], array_column(array_filter($actions, static fn (array $action): bool => true === $action['fitsCard']), 'action'));
    }

    public function test_a_card_waiting_for_a_review_fits_review_open_send_agent_start_session_and_dismiss(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-actions-review');
        $card = $this->card($this->em, $project);
        $this->documentInReview($project, $card);
        $this->reconcileWaits($project, $card);
        $this->actAsMcpTokenBoundTo($project);

        $actions = ($this->tool)((string) $card->id)['actions'];

        self::assertSame(['review', 'open', 'send-agent', 'start-session', 'dismiss'], array_column(array_filter($actions, static fn (array $action): bool => true === $action['fitsCard']), 'action'));
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
}
