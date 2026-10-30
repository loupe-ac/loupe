<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Mcp\InboxActionRunTool;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardPauses;
use App\Module\Workflow\Contract\PauseKind;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\McpTokenScenario;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class InboxActionRunToolTest extends KernelTestCase
{
    use AgentResponseScenario;
    use InboxToolScenario;
    use McpTokenScenario;
    use WorkflowProjects;

    private const string PROPOSED_ENTRY = "action: command\nparams:\n  out: { pattern: '[a-z/]+' }\nrun: [make, export]";

    private EntityManagerInterface $em;
    private InboxActionRunTool $tool;
    private Project $project;
    private Card $card;
    private Bridge $bridge;
    private int $number = 0;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        RecordingAuditor::installedIn(self::getContainer());

        $tool = self::getContainer()->get(InboxActionRunTool::class);
        self::assertInstanceOf(InboxActionRunTool::class, $tool);
        $this->tool = $tool;

        $this->project = $this->workflowProject('inbox-action-run');
        $this->bindLifecycle($this->project);
        $createCard = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $createCard);
        $this->card = $createCard(new CreateCardCommand(
            project: $this->project,
            title: 'Add a tag filter',
            body: 'Body',
            type: 'feature',
            column: $this->column($this->project, 'in-progress'),
            reporter: Actor::Human,
        ));
        $this->bridge = new Bridge($this->project->owner, Uuid::v4(), [], 'b4e39aa7', new \DateTimeImmutable());
        $this->bridge->capabilities = [Bridge::CAPABILITY_COMMANDS];
        $this->em->persist($this->bridge);
        $this->em->flush();
    }

    public function test_the_tool_refuses_while_the_flag_is_off(): void
    {
        $this->actAsMcpTokenBoundTo($this->project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The inbox is switched off on this instance.');
        ($this->tool)((string) Uuid::v4(), 'dismiss');
    }

    public function test_a_resume_queues_the_bridge_command_for_the_token_user(): void
    {
        $item = $this->runItem();
        $this->readyTool();

        $result = $this->runOf(($this->tool)((string) $item->id, 'resume-run', 'Carry on.'));

        self::assertNotNull($result['actionId']);
        $action = $this->em->find(InboxAction::class, Uuid::fromString($result['actionId']));
        self::assertSame(['Carry on.', $this->project->owner->id?->toRfc4122()], [$action?->note, $action?->requestedBy?->id?->toRfc4122()]);
        self::assertSame(BridgeCommandState::Pending, $this->em->find(BridgeCommand::class, $action?->bridgeCommandId)?->state);
    }

    public function test_a_retry_releases_the_pause(): void
    {
        $pause = $this->pause();
        $item = $this->waitItem(new InboxCardWait(
            $this->watch(),
            'paused', InboxCardWaitType::CardPause, 'marker.paused',
            pauseId: $pause,
        ));
        $this->readyTool();

        $result = $this->runOf(($this->tool)((string) $item->id, 'retry-card'));

        self::assertNotNull($result['actionId']);
        $pauses = self::getContainer()->get(CardPauses::class);
        self::assertInstanceOf(CardPauses::class, $pauses);
        self::assertNull($pauses->findActive($this->card->id ?? throw new \LogicException('Stored.')));
    }

    public function test_a_dismiss_closes_the_item_through_an_agent_and_records_no_action(): void
    {
        $item = $this->runItem();
        $this->readyTool();

        $result = $this->runOf(($this->tool)((string) $item->id, 'dismiss', 'Not now'));

        self::assertNull($result['actionId']);
        self::assertSame('declined', $result['item']['state']);
        $stored = $this->stored((string) $item->id);
        self::assertSame(['Not now', true], [$stored->note, InboxCloser::OwnerThroughAgent === $stored->closedBy]);
    }

    public function test_a_session_sends_the_prompt_that_the_asking_agent_suggested(): void
    {
        $this->bridge->capabilities = [Bridge::CAPABILITY_COMMANDS, Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_SESSION];
        $this->bridge->projects = [(string) $this->project->id];
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Todo, title: 'Rebuild it', blocking: false, origin: InboxAskOrigin::Agent);
        $item->cards->add(new InboxItemCard($item, $this->card));
        $item->suggestedActions = [['action' => 'start-session', 'reason' => 'Talk it through.', 'recommended' => true, 'cardId' => (string) $this->card->id, 'prompt' => 'Use the skill.']];
        $this->em->persist($item);
        $this->em->flush();
        $this->readyTool();

        $result = $this->runOf(($this->tool)((string) $item->id, 'start-session'));

        $action = $this->em->find(InboxAction::class, Uuid::fromString($result['actionId'] ?? throw new \LogicException('A session records a row.')));
        self::assertInstanceOf(InboxAction::class, $action);
        self::assertSame('Use the skill.', $action->sessionPrompt);
        self::assertSame('Use the skill.', $this->em->find(WorkRequest::class, $action->workRequestId)?->prompt);
    }

    public function test_a_session_sends_the_prompt_that_the_owner_edited_in_chat(): void
    {
        $item = $this->sessionTodo();

        $result = $this->runOf(($this->tool)((string) $item->id, 'start-session', sessionPrompt: 'Read the plan first.'));

        $action = $this->em->find(InboxAction::class, Uuid::fromString($result['actionId'] ?? throw new \LogicException('A session records a row.')));
        self::assertInstanceOf(InboxAction::class, $action);
        self::assertSame('Read the plan first.', $action->sessionPrompt);
        self::assertSame('Read the plan first.', $this->em->find(WorkRequest::class, $action->workRequestId)?->prompt);
    }

    public function test_a_session_whose_prompt_the_owner_cleared_sends_no_prompt(): void
    {
        $item = $this->sessionTodo();

        $result = $this->runOf(($this->tool)((string) $item->id, 'start-session', sessionPrompt: ''));

        $action = $this->em->find(InboxAction::class, Uuid::fromString($result['actionId'] ?? throw new \LogicException('A session records a row.')));
        self::assertInstanceOf(InboxAction::class, $action);
        self::assertNull($action->sessionPrompt);
        self::assertNull($this->em->find(WorkRequest::class, $action->workRequestId)?->prompt);
    }

    public function test_a_session_prompt_over_the_limit_is_refused(): void
    {
        $item = $this->sessionTodo();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('sessionPrompt: A session prompt must be at most 2000 characters on this instance.');
        ($this->tool)((string) $item->id, 'start-session', sessionPrompt: str_repeat('a', 2001));
    }

    private function sessionTodo(): InboxItem
    {
        $this->bridge->capabilities = [Bridge::CAPABILITY_COMMANDS, Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_SESSION];
        $this->bridge->projects = [(string) $this->project->id];
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Todo, title: 'Rebuild it', blocking: false, origin: InboxAskOrigin::Agent);
        $item->cards->add(new InboxItemCard($item, $this->card));
        $item->suggestedActions = [['action' => 'start-session', 'reason' => 'Talk it through.', 'recommended' => true, 'cardId' => (string) $this->card->id, 'prompt' => 'Use the skill.']];
        $this->em->persist($item);
        $this->em->flush();
        $this->readyTool();

        return $item;
    }

    public function test_a_run_command_without_a_digest_runs_nothing_and_returns_the_preview(): void
    {
        $item = $this->commandTodo();
        $this->readyTool();

        $preview = $this->previewOf(($this->tool)((string) $item->id, 'run-command'));

        self::assertTrue($preview['preview']);
        self::assertSame(['design-export', 'epic/42', ['bin/console', 'app:export', '{out}'], ['out' => 'var/out']], [$preview['entry'], $preview['branch'], $preview['runList'], $preview['params']]);
        self::assertSame(['bin/console', 'app:export', 'var/out'], $preview['argv']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $preview['runDigest']);
        self::assertNull($preview['disabledReason']);
        self::assertSame(0, $this->workRequestCount());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbox_actions'));
    }

    public function test_a_run_command_with_the_digest_of_the_preview_queues_the_request_with_the_confirmed_values(): void
    {
        $item = $this->commandTodo();
        $this->readyTool();
        $preview = $this->previewOf(($this->tool)((string) $item->id, 'run-command', branch: 'main', params: ['out' => 'other/folder']));

        $result = $this->runOf(($this->tool)((string) $item->id, 'run-command', branch: 'main', params: ['out' => 'other/folder'], runDigest: $preview['runDigest']));

        $action = $this->em->find(InboxAction::class, Uuid::fromString($result['actionId'] ?? ''));
        $request = $this->em->find(WorkRequest::class, $action?->workRequestId);
        self::assertSame(['design-export', (string) $this->bridge->id, 'main', ['out' => 'other/folder']], [$request?->kind, (string) $request?->targetBridgeId, $request?->branch, $request?->params]);
    }

    public function test_a_run_command_with_a_wrong_digest_is_refused_and_queues_nothing(): void
    {
        $item = $this->commandTodo();
        $this->readyTool();

        try {
            ($this->tool)((string) $item->id, 'run-command', runDigest: 'not-the-digest');
            self::fail('Expected a refusal for a wrong digest.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('action: The command changed on the bridge', $e->getMessage());
        }

        self::assertSame(0, $this->workRequestCount());
    }

    public function test_a_digest_does_not_cover_other_params_than_the_preview_showed(): void
    {
        $item = $this->commandTodo();
        $this->readyTool();
        $preview = $this->previewOf(($this->tool)((string) $item->id, 'run-command', params: ['out' => 'one']));

        try {
            ($this->tool)((string) $item->id, 'run-command', params: ['out' => 'two'], runDigest: $preview['runDigest']);
            self::fail('Expected a refusal for other params.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('action: The command changed on the bridge', $e->getMessage());
        }

        self::assertSame(0, $this->workRequestCount());
    }

    public function test_a_digest_of_one_item_does_not_cover_another_item_with_the_same_command(): void
    {
        $previewed = $this->commandTodo();
        $other = $this->commandTodo();
        $this->readyTool();
        $preview = $this->previewOf(($this->tool)((string) $previewed->id, 'run-command'));

        $this->assertCommandChanged((string) $other->id, $preview['runDigest']);
    }

    public function test_a_digest_does_not_cover_another_entry_with_the_same_run_list(): void
    {
        $item = $this->commandTodo();
        $this->readyTool();
        $preview = $this->previewOf(($this->tool)((string) $item->id, 'run-command'));
        $this->bridge->commandEntries = [...$this->bridge->commandEntries ?? [], ['name' => 'design-copy', 'description' => null, 'params' => [['name' => 'out', 'pattern' => '[a-z/]+', 'description' => null]], 'run' => ['bin/console', 'app:export', '{out}'], 'timeout' => '10m']];
        $item->suggestedActions = [['action' => 'run-command', 'reason' => 'Export it.', 'recommended' => true, 'cardId' => null, 'command' => ['entry' => 'design-copy', 'branch' => 'epic/42', 'params' => ['out' => 'var/out']], 'proposedEntry' => null]];
        $this->em->flush();

        $this->assertCommandChanged((string) $item->id, $preview['runDigest']);
    }

    public function test_a_digest_does_not_cover_the_same_command_on_another_bridge(): void
    {
        $item = $this->commandTodo();
        $this->readyTool();
        $preview = $this->previewOf(($this->tool)((string) $item->id, 'run-command'));
        $other = new Bridge($this->project->owner, Uuid::v4(), [], 'c5f40bb8', new \DateTimeImmutable());
        $other->capabilities = $this->bridge->capabilities;
        $other->projects = $this->bridge->projects;
        $other->lastSeenAt = $this->bridge->lastSeenAt;
        $other->commandEntries = $this->bridge->commandEntries;
        $other->worktrees = $this->bridge->worktrees;
        $this->em->persist($other);
        $ask = $this->lastAsk ?? throw new \LogicException('The item has an ask.');
        $ask->bridgeId = $other->id;
        $this->em->flush();

        $this->assertCommandChanged((string) $item->id, $preview['runDigest']);
    }

    public function test_an_add_entry_queues_the_request_for_the_asking_bridge(): void
    {
        $item = $this->commandTodo(self::PROPOSED_ENTRY);
        $this->bridge->commandEntries = [];
        $this->bridge->capabilities = [...$this->bridge->capabilities ?? [], Bridge::CAPABILITY_ADD_ENTRY];
        $this->em->flush();
        $this->readyTool();

        $result = $this->runOf(($this->tool)((string) $item->id, 'add-entry', 'Add it.'));

        $action = $this->em->find(InboxAction::class, Uuid::fromString($result['actionId'] ?? ''));
        $request = $this->em->find(WorkRequest::class, $action?->workRequestId);
        self::assertSame(['Add it.', 'add-entry', (string) $this->bridge->id, (string) $item->id], [$action?->note, $request?->kind, (string) $request?->targetBridgeId, (string) $request?->inboxItemId]);
        self::assertStringContainsString('design-export:', (string) $request?->prompt);
    }

    public function test_an_add_entry_the_bridge_cannot_take_is_refused_and_queues_nothing(): void
    {
        $item = $this->commandTodo(self::PROPOSED_ENTRY);
        $this->bridge->commandEntries = [];
        $this->em->flush();
        $this->readyTool();

        try {
            ($this->tool)((string) $item->id, 'add-entry');
            self::fail('Expected a refusal for a bridge that takes no entry.');
        } catch (ToolCallException $e) {
            self::assertStringStartsWith('action: This action does not fit the item now.', $e->getMessage());
        }

        self::assertSame(0, $this->workRequestCount());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbox_actions'));
    }

    public function test_a_run_command_with_a_bad_value_is_refused_and_queues_nothing(): void
    {
        $item = $this->commandTodo();
        $this->readyTool();
        $preview = $this->previewOf(($this->tool)((string) $item->id, 'run-command', params: ['out' => '--exec=1']));

        try {
            ($this->tool)((string) $item->id, 'run-command', params: ['out' => '--exec=1'], runDigest: $preview['runDigest']);
            self::fail('Expected a refusal for a bad value.');
        } catch (ToolCallException $e) {
            self::assertSame('action: A param value does not fit the pattern of its param.', $e->getMessage());
        }

        self::assertSame(0, $this->workRequestCount());
    }

    public function test_the_preview_names_the_reason_a_command_cannot_run(): void
    {
        $item = $this->commandTodo();
        $this->bridge->commandEntries = [];
        $this->em->flush();
        $this->readyTool();

        $preview = $this->previewOf(($this->tool)((string) $item->id, 'run-command'));

        self::assertSame('The rule file of the bridge has no such command.', $preview['disabledReason']);
    }

    public function test_a_preview_of_an_item_with_no_command_suggestion_is_refused(): void
    {
        $this->actAsMcpTokenBoundTo($this->project);
        $this->enableInbox();
        $todo = $this->askTodo();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('action: This action does not fit the item now.');
        ($this->tool)($todo['itemId'], 'run-command');
    }

    public function test_an_action_that_does_not_fit_the_item_is_refused(): void
    {
        $this->actAsMcpTokenBoundTo($this->project);
        $this->enableInbox();
        $todo = $this->askTodo();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('action: This action does not fit the item now.');
        ($this->tool)($todo['itemId'], 'resume-run');
    }

    #[DataProvider('refusedActions')]
    public function test_a_link_and_an_unknown_action_are_refused(string $action, string $message): void
    {
        $item = $this->runItem();
        $this->readyTool();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage($message);
        ($this->tool)((string) $item->id, $action);
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusedActions(): iterable
    {
        yield 'open' => ['open', 'is a link to a page'];
        yield 'review' => ['review', 'A review verdict stays on the inbox page.'];
        yield 'unknown' => ['launch', 'unknown action "launch"'];
    }

    public function test_a_note_over_the_limit_is_refused(): void
    {
        $item = $this->runItem();
        $this->readyTool();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('note: A note must be at most 2000 characters.');
        ($this->tool)((string) $item->id, 'resume-run', str_repeat('a', 2001));
    }

    public function test_a_worker_session_is_refused_and_nothing_runs(): void
    {
        $item = $this->commandTodo();
        $this->readyTool();
        $this->asWorkerSession($this->project);

        try {
            ($this->tool)((string) $item->id, 'dismiss');
            self::fail('Expected a refusal for a worker session.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('A bridge worker cannot answer an inbox item.', $e->getMessage());
        }

        self::assertSame(InboxItemState::Open, $this->stored((string) $item->id)->state);
    }

    public function test_an_item_of_another_project_is_not_reachable(): void
    {
        $item = $this->runItem();
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-action-run-mine'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('not found or not accessible');
        ($this->tool)((string) $item->id, 'dismiss');
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function previewOf(array $result): array
    {
        self::assertTrue($result['preview']);

        return $result;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function runOf(array $result): array
    {
        self::assertFalse($result['preview']);

        return $result;
    }

    private function readyTool(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->project);
    }

    private function assertCommandChanged(string $itemId, string $runDigest): void
    {
        try {
            ($this->tool)($itemId, 'run-command', runDigest: $runDigest);
            self::fail('Expected a refusal for a digest of another command.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('action: The command changed on the bridge', $e->getMessage());
        }

        self::assertSame(0, $this->workRequestCount());
    }

    private function workRequestCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM work_requests');
    }

    private function runItem(): InboxItem
    {
        $run = new WorkerRun(
            project: $this->project,
            bridgeId: $this->bridge->id,
            subjectType: WorkSubject::CARD,
            subjectId: $this->card->id ?? throw new \LogicException('Stored.'),
            cardNumber: $this->card->number,
            workKind: 'implement',
            state: WorkerRunState::Blocked,
            runKey: Uuid::v7(),
            sessionId: Uuid::v4(),
            output: 'Needs the API key.',
        );
        $this->em->persist($run);
        $this->em->flush();

        return $this->waitItem(new InboxCardWait($this->watch(), 'run-needs-you', InboxCardWaitType::WorkerRun, 'marker.run_needs_you', runId: $run->id));
    }

    private ?InboxCardWatch $watch = null;

    private function watch(): InboxCardWatch
    {
        if (null === $this->watch) {
            $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Wait, title: '#1 Ship it', blocking: true, origin: InboxAskOrigin::Loupe);
            $this->em->persist($item);
            $this->watch = new InboxCardWatch($item, $this->card->id ?? throw new \LogicException('Stored.'), $this->card->number);
            $this->em->persist($this->watch);
        }

        return $this->watch;
    }

    private function waitItem(InboxCardWait $wait): InboxItem
    {
        $watch = $this->watch();
        $watch->waits->add($wait);
        $watch->item->cards->add(new InboxItemCard($watch->item, $this->card));
        $this->em->flush();
        $this->hold($watch->item);

        return $watch->item;
    }

    private function pause(): Uuid
    {
        $handler = self::getContainer()->get(PauseCardHandler::class);
        self::assertInstanceOf(PauseCardHandler::class, $handler);

        return $handler(new PauseCardCommand($this->card, 'on-hold', 'review-rule', PauseKind::Retries))->id
            ?? throw new \LogicException('The card had no pause.');
    }

    private function commandTodo(?string $proposedEntry = null): InboxItem
    {
        $this->bridge->capabilities = [Bridge::CAPABILITY_COMMANDS, Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_RUN_COMMAND];
        $this->bridge->projects = [(string) $this->project->id];
        $this->bridge->lastSeenAt = new \DateTimeImmutable();
        $this->bridge->commandEntries = [['name' => 'design-export', 'description' => null, 'params' => [['name' => 'out', 'pattern' => '[a-z/]+', 'description' => null]], 'run' => ['bin/console', 'app:export', '{out}'], 'timeout' => '10m']];
        $this->bridge->worktrees = [
            ['projectId' => (string) $this->project->id, 'branch' => 'main', 'path' => '/work/loupe'],
            ['projectId' => (string) $this->project->id, 'branch' => 'epic/42', 'path' => '/work/loupe/.worktrees/card-42'],
        ];
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Todo, title: 'Export the design system', blocking: false, origin: InboxAskOrigin::Agent);
        $item->suggestedActions = [['action' => 'run-command', 'reason' => 'Export it.', 'recommended' => true, 'cardId' => null, 'command' => ['entry' => 'design-export', 'branch' => 'epic/42', 'params' => ['out' => 'var/out']], 'proposedEntry' => $proposedEntry]];
        $item->cards->add(new InboxItemCard($item, $this->card));
        $this->em->persist($item);
        $this->em->flush();
        $this->hold($item)->bridgeId = $this->bridge->id;
        $this->em->flush();

        return $item;
    }

    private ?InboxAsk $lastAsk = null;

    private function hold(InboxItem $item): InboxAsk
    {
        $ask = new InboxAsk(project: $this->project, sessionId: Uuid::v4(), bridgeId: Uuid::v4());
        $ask->items->add(new InboxAskItem($ask, $item));
        $this->em->persist($ask);
        $this->em->flush();

        return $this->lastAsk = $ask;
    }
}
