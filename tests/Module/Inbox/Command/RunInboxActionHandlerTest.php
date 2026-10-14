<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Bridge\Command\CancelBridgeCommandHandler;
use App\Module\Bridge\Command\OpenWorkRequestHandler;
use App\Module\Bridge\Command\RequestBridgeCommandHandler;
use App\Module\Bridge\Command\WithdrawWorkRequestHandler;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\BridgeLiveness;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\ValueObject\BridgeCommandCause;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Bridge\View\WorkerRunControls;
use App\Module\Inbox\Command\CancelInboxActionCommand;
use App\Module\Inbox\Command\CancelInboxActionHandler;
use App\Module\Inbox\Command\DeclineInboxItemHandler;
use App\Module\Inbox\Command\RunInboxActionCommand;
use App\Module\Inbox\Command\RunInboxActionHandler;
use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitReason;
use App\Module\Inbox\Entity\InboxCardWaitTrigger;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Repository\InboxActionRepository;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Inbox\Service\ItemActions;
use App\Module\Inbox\Service\SessionBridges;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Command\ReleaseWorkflowPauseHandler;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardDirectory;
use App\Module\Workflow\Contract\CardPauses;
use App\Module\Workflow\Contract\PauseKind;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\RecordingAuditor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class RunInboxActionHandlerTest extends KernelTestCase
{
    use WorkflowProjects;

    private Project $project;
    private Card $card;
    private Bridge $bridge;
    private RecordingAuditor $audit;
    private int $number = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->audit = RecordingAuditor::installedIn(self::getContainer());
        $this->project = $this->workflowProject('inbox-run-action');
        $this->bindLifecycle($this->project);
        $this->card = $this->service(CreateCardHandler::class)(new CreateCardCommand(
            project: $this->project,
            title: 'Card',
            body: 'Body',
            type: 'feature',
            column: $this->column($this->project, 'tech-design'),
            reporter: Actor::Human,
        ));
        $this->bridge = new Bridge($this->project->owner, Uuid::v4(), [], 'b4e39aa7', new \DateTimeImmutable());
        $this->bridge->capabilities = [Bridge::CAPABILITY_COMMANDS];
        $this->em()->persist($this->bridge);
        $this->em()->flush();
        $this->audit->forget();
    }

    public function test_a_resume_queues_a_pending_command_with_the_cause_and_records_the_action(): void
    {
        $item = $this->runItem();
        $run = $this->em()->find(WorkerRun::class, $this->waitOf($item)->runId);

        $action = $this->runAction($item, InboxActionKind::ResumeRun, '  Carry on with the tests.  ');

        self::assertInstanceOf(InboxAction::class, $action);
        self::assertSame('Carry on with the tests.', $action->note);
        self::assertSame($this->project->owner, $action->requestedBy);
        $command = $this->em()->find(BridgeCommand::class, $action->bridgeCommandId);
        self::assertInstanceOf(BridgeCommand::class, $command);
        self::assertSame([BridgeCommandKind::ResumeRun, BridgeCommandState::Pending, BridgeCommandCause::InboxAction], [$command->kind, $command->state, $command->cause]);
        self::assertSame((string) $run?->id, (string) $command->workerRun->id);
        self::assertNull($command->reason);
        self::assertSame($action, $this->service(InboxActionRepository::class)->findOneByIdAndItem($action->id ?? throw new \LogicException('Stored.'), $item));
    }

    public function test_a_second_resume_is_refused_while_the_command_is_pending(): void
    {
        $item = $this->runItem();
        $this->runAction($item, InboxActionKind::ResumeRun);

        $this->assertRefused($item, InboxActionKind::ResumeRun, ['action' => RunInboxActionHandler::NOT_AVAILABLE]);
        self::assertCount(1, $this->service(InboxActionRepository::class)->findByItem($item));
    }

    /** The card pauses after the menu fit, so the bridge refuses the resume the menu offered. */
    public function test_a_refused_bridge_request_leaves_no_row_and_no_command(): void
    {
        $item = $this->runItem();
        $this->service(CardHolds::class)->hold($this->project, $this->card->id ?? throw new \LogicException('Stored.'), $this->project->owner);

        $this->assertRefused($item, InboxActionKind::ResumeRun, ['action' => RequestBridgeCommandHandler::CARD_HELD]);

        $this->em()->clear();
        self::assertSame([], $this->service(InboxActionRepository::class)->findByItem($item));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_commands'));
        self::assertSame([], $this->audit->records('inbox.action_requested'));
    }

    public function test_a_refusal_of_the_release_keeps_its_key_under_the_action_field(): void
    {
        $item = $this->pauseItem(PauseKind::Retries);
        $this->service(BoardAutomation::class)->settingsForUpdate($this->project)->enabled = false;
        $this->em()->flush();

        $this->assertRefused($item, InboxActionKind::RetryCard, ['action' => ReleaseWorkflowPauseHandler::CARD_UNMANAGED]);
        self::assertSame([], $this->service(InboxActionRepository::class)->findByItem($item));
        self::assertSame([], $this->audit->records('inbox.action_requested'));
    }

    public function test_a_retry_releases_the_pause_and_stores_its_id(): void
    {
        $item = $this->pauseItem(PauseKind::Retries);

        $action = $this->runAction($item, InboxActionKind::RetryCard, 'Try again');

        self::assertInstanceOf(InboxAction::class, $action);
        self::assertSame((string) $this->waitOf($item)->pauseId, (string) $action->pauseId);
        self::assertNull($action->bridgeCommandId);
        self::assertNull($this->service(CardPauses::class)->findActive($this->card->id ?? throw new \LogicException('Stored.')));
    }

    public function test_a_retry_of_a_rule_pause_is_refused(): void
    {
        $item = $this->pauseItem(PauseKind::Rule);

        $this->assertRefused($item, InboxActionKind::RetryCard, ['action' => RunInboxActionHandler::NOT_AVAILABLE]);
        self::assertNotNull($this->service(CardPauses::class)->findActive($this->card->id ?? throw new \LogicException('Stored.')));
    }

    public function test_a_dismiss_closes_the_item_as_declined_with_the_note_and_records_no_row(): void
    {
        $item = $this->runItem();

        $action = $this->runAction($item, InboxActionKind::Dismiss, 'Not now');

        self::assertNull($action);
        self::assertSame([InboxItemState::Declined, 'Not now'], [$item->state, $item->closeNote]);
        self::assertSame([], $this->service(InboxActionRepository::class)->findByItem($item));
        self::assertSame(
            ['itemId' => (string) $item->id, 'projectId' => (string) $this->project->id, 'kind' => 'dismiss', 'hasNote' => true],
            $this->audit->record('inbox.action_requested')->context,
        );
    }

    public function test_a_dismiss_of_a_closed_item_is_refused(): void
    {
        $item = $this->runItem();
        $this->runAction($item, InboxActionKind::Dismiss);

        $this->assertRefused($item, InboxActionKind::Dismiss, ['action' => RunInboxActionHandler::NOT_AVAILABLE]);
    }

    public function test_a_kind_that_is_not_run_here_is_refused_even_when_it_fits(): void
    {
        $item = $this->runItem();

        foreach ([InboxActionKind::Open, InboxActionKind::Review, InboxActionKind::SendAgent] as $kind) {
            $this->assertRefused($item, $kind, ['action' => RunInboxActionHandler::NOT_AVAILABLE]);
        }
    }

    public function test_a_kind_that_does_not_fit_is_refused(): void
    {
        $item = $this->runItem(WorkerRunState::Running);

        $this->assertRefused($item, InboxActionKind::ResumeRun, ['action' => RunInboxActionHandler::NOT_AVAILABLE]);
        $this->assertRefused($item, InboxActionKind::RetryCard, ['action' => RunInboxActionHandler::NOT_AVAILABLE]);
    }

    public function test_a_note_over_the_limit_is_refused_and_one_at_the_limit_is_kept(): void
    {
        $item = $this->runItem();

        $this->assertRefused($item, InboxActionKind::ResumeRun, ['note' => RunInboxActionHandler::NOTE_TOO_LONG], note: str_repeat('é', InboxAction::MAX_NOTE_LENGTH + 1));
        $action = $this->runAction($item, InboxActionKind::ResumeRun, ' '.str_repeat('é', InboxAction::MAX_NOTE_LENGTH).' ');

        self::assertInstanceOf(InboxAction::class, $action);
        self::assertSame(InboxAction::MAX_NOTE_LENGTH, mb_strlen($action->note));
    }

    public function test_the_request_is_audited_with_ids_and_no_note_text(): void
    {
        $item = $this->runItem();

        $action = $this->runAction($item, InboxActionKind::ResumeRun, 'Secret words');

        $record = $this->audit->record('inbox.action_requested');
        self::assertSame([
            'itemId' => (string) $item->id,
            'projectId' => (string) $this->project->id,
            'kind' => 'resume-run',
            'hasNote' => true,
            'actionId' => (string) $action?->id,
        ], $record->context);
        self::assertStringNotContainsString('Secret words', json_encode($record->context, \JSON_THROW_ON_ERROR));
        self::assertSame([], $this->audit->records('inbox.action_cancelled'));
    }

    public function test_a_refused_action_leaves_no_audit_record(): void
    {
        $item = $this->runItem(WorkerRunState::Running);

        $this->assertRefused($item, InboxActionKind::ResumeRun, ['action' => RunInboxActionHandler::NOT_AVAILABLE]);

        self::assertSame([], $this->audit->records('inbox.action_requested'));
    }

    public function test_a_cancel_withdraws_the_pending_command_and_marks_the_action(): void
    {
        $item = $this->runItem();
        $action = $this->runAction($item, InboxActionKind::ResumeRun) ?? throw new \LogicException('A resume records a row.');

        ($this->cancelHandler())(new CancelInboxActionCommand($action, $item, $this->project->owner));

        self::assertNotNull($action->cancelledAt);
        $command = $this->em()->find(BridgeCommand::class, $action->bridgeCommandId);
        self::assertSame(BridgeCommandState::Cancelled, $command?->state);
        $record = $this->audit->record('inbox.action_cancelled');
        self::assertSame(['itemId' => (string) $item->id, 'projectId' => (string) $this->project->id, 'kind' => 'resume-run', 'actionId' => (string) $action->id], $record->context);
    }

    public function test_a_cancel_with_another_item_is_refused_and_changes_nothing(): void
    {
        $item = $this->runItem();
        $action = $this->runAction($item, InboxActionKind::ResumeRun) ?? throw new \LogicException('A resume records a row.');
        $other = new InboxItem(project: $this->project, number: ++$this->number + 50, kind: InboxItemKind::Wait, title: 'Other', blocking: true);
        $this->em()->persist($other);
        $this->em()->flush();

        $this->assertCancelRefused($action, $other, CancelInboxActionHandler::NOT_FOUND);

        self::assertSame(BridgeCommandState::Pending, $this->em()->find(BridgeCommand::class, $action->bridgeCommandId)?->state);
    }

    public function test_a_cancel_of_a_retry_is_refused(): void
    {
        $item = $this->pauseItem(PauseKind::Retries);
        $action = $this->runAction($item, InboxActionKind::RetryCard) ?? throw new \LogicException('A retry records a row.');

        $this->assertCancelRefused($action, $item, CancelInboxActionHandler::NOT_CANCELLABLE);
    }

    public function test_a_cancel_of_a_cancelled_or_settled_resume_is_refused(): void
    {
        $item = $this->runItem();
        $action = $this->runAction($item, InboxActionKind::ResumeRun) ?? throw new \LogicException('A resume records a row.');
        ($this->cancelHandler())(new CancelInboxActionCommand($action, $item, $this->project->owner));

        $this->assertCancelRefused($action, $item, CancelInboxActionHandler::NOT_CANCELLABLE);

        $settled = new InboxAction($item, InboxActionKind::ResumeRun, $this->project->owner);
        $settled->bridgeCommandId = $action->bridgeCommandId;
        $this->em()->persist($settled);
        $this->em()->flush();
        $this->assertCancelRefused($settled, $item, CancelInboxActionHandler::NOT_CANCELLABLE);
        self::assertNull($settled->cancelledAt);
    }

    public function test_a_command_that_is_no_longer_pending_is_not_cancelled(): void
    {
        $item = $this->runItem();
        $action = $this->runAction($item, InboxActionKind::ResumeRun) ?? throw new \LogicException('A resume records a row.');
        $command = $this->em()->find(BridgeCommand::class, $action->bridgeCommandId) ?? throw new \LogicException('Stored.');
        $command->state = BridgeCommandState::Done;
        $this->em()->flush();

        $this->assertCancelRefused($action, $item, CancelInboxActionHandler::NOT_CANCELLABLE);
        self::assertNull($action->cancelledAt);
        self::assertSame([], $this->audit->records('inbox.action_cancelled'));
    }

    public function test_a_session_opens_a_work_request_for_the_card_and_records_the_action(): void
    {
        $this->sessionBridge();
        $item = $this->runItem();

        $action = $this->runAction($item, InboxActionKind::StartSession, 'Pair with me') ?? throw new \LogicException('A session records a row.');

        $request = $this->em()->find(WorkRequest::class, $action->workRequestId);
        self::assertInstanceOf(WorkRequest::class, $request);
        self::assertSame(
            [WorkSubject::CARD, (string) $this->card->id, $this->card->number, 'session', 'session', 'inbox:start-session', WorkRequestState::Open, null],
            [$request->subjectType, (string) $request->subjectId, $request->cardNumber, $request->kind, $request->capability, $request->ruleId, $request->state, $request->bridgeId],
        );
        self::assertSame([(string) $action->id, $item->number, (string) $item->id], [$request->context->inboxActionId, $request->context->inboxItemNumber, $request->context->inboxItemId]);
        self::assertNull($request->prompt);
        self::assertNull($action->bridgeCommandId);
        self::assertSame('Pair with me', $action->note);
        self::assertSame((string) $action->id, $this->audit->record('inbox.action_requested')->context['actionId']);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM outbox_events WHERE type = 'bridge.work_request'"));
    }

    public function test_a_session_never_resumes_the_session_of_an_earlier_run(): void
    {
        $this->sessionBridge();
        $item = $this->runItem();
        $previous = new WorkRequest($this->project, WorkSubject::CARD, $this->card->id ?? throw new \LogicException('Stored.'), $this->card->number, 'session', 'session', RunInboxActionHandler::SESSION_RULE_ID, new \DateTimeImmutable('-1 hour'));
        $previous->state = WorkRequestState::Done;
        $this->em()->persist($previous);
        $this->em()->flush();
        $this->em()->persist(new WorkerRun(
            project: $this->project,
            bridgeId: $this->bridge->id,
            subjectType: WorkSubject::CARD,
            subjectId: $this->card->id,
            cardNumber: $this->card->number,
            workKind: 'session',
            state: WorkerRunState::Unfinished,
            runKey: Uuid::v7(),
            sessionId: Uuid::v4(),
            endedAt: new \DateTimeImmutable('-30 minutes'),
            workRequestId: $previous->id,
        ));
        $this->em()->flush();

        $action = $this->runAction($item, InboxActionKind::StartSession) ?? throw new \LogicException('A session records a row.');

        self::assertNull($this->em()->find(WorkRequest::class, $action->workRequestId)?->resumeSessionId);
    }

    public function test_a_session_is_refused_while_no_bridge_can_open_one(): void
    {
        $item = $this->runItem();

        $this->assertRefused($item, InboxActionKind::StartSession, ['action' => RunInboxActionHandler::NOT_AVAILABLE]);

        self::assertSame([], $this->service(InboxActionRepository::class)->findByItem($item));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM work_requests'));
        self::assertSame([], $this->audit->records('inbox.action_requested'));
    }

    public function test_a_session_is_refused_for_an_item_with_no_card(): void
    {
        $this->sessionBridge();
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Wait, title: 'No card', blocking: true);
        $this->em()->persist($item);
        $this->em()->flush();

        $this->assertRefused($item, InboxActionKind::StartSession, ['action' => RunInboxActionHandler::NOT_AVAILABLE]);
    }

    public function test_a_session_is_refused_while_a_session_request_of_the_card_is_live_and_leaves_no_row(): void
    {
        $this->sessionBridge();
        $first = $this->runItem();
        $this->runAction($first, InboxActionKind::StartSession);
        $second = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Question, title: 'Which column?', blocking: true);
        $second->cards->add(new InboxItemCard($second, $this->card));
        $this->em()->persist($second);
        $this->em()->flush();

        $this->assertRefused($second, InboxActionKind::StartSession, ['action' => RunInboxActionHandler::NOT_AVAILABLE]);

        $this->em()->clear();
        self::assertSame([], $this->service(InboxActionRepository::class)->findByItem($second));
        self::assertCount(1, $this->audit->records('inbox.action_requested'));
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM work_requests'));
    }

    public function test_a_cancel_withdraws_the_open_session_request_and_marks_the_action(): void
    {
        $this->sessionBridge();
        $item = $this->runItem();
        $action = $this->runAction($item, InboxActionKind::StartSession) ?? throw new \LogicException('A session records a row.');

        ($this->cancelHandler())(new CancelInboxActionCommand($action, $item, $this->project->owner));

        self::assertNotNull($action->cancelledAt);
        self::assertSame(WorkRequestState::Cancelled, $this->em()->find(WorkRequest::class, $action->workRequestId)?->state);
        self::assertSame('start-session', $this->audit->record('inbox.action_cancelled')->context['kind']);
    }

    public function test_a_cancel_of_a_claimed_session_request_is_refused(): void
    {
        $this->sessionBridge();
        $item = $this->runItem();
        $action = $this->runAction($item, InboxActionKind::StartSession) ?? throw new \LogicException('A session records a row.');
        $request = $this->em()->find(WorkRequest::class, $action->workRequestId) ?? throw new \LogicException('Stored.');
        $request->state = WorkRequestState::Claimed;
        $this->em()->flush();

        $this->assertCancelRefused($action, $item, CancelInboxActionHandler::NOT_CANCELLABLE);

        self::assertNull($action->cancelledAt);
        self::assertSame(WorkRequestState::Claimed, $this->em()->find(WorkRequest::class, $action->workRequestId)?->state);
        self::assertSame([], $this->audit->records('inbox.action_cancelled'));
    }

    public function test_a_cancel_of_a_session_with_no_request_is_refused(): void
    {
        $item = $this->runItem();
        $action = new InboxAction($item, InboxActionKind::StartSession, $this->project->owner);
        $this->em()->persist($action);
        $this->em()->flush();

        $this->assertCancelRefused($action, $item, CancelInboxActionHandler::NOT_CANCELLABLE);
    }

    private function sessionBridge(): void
    {
        $this->bridge->capabilities = [Bridge::CAPABILITY_COMMANDS, Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_SESSION];
        $this->bridge->projects = [(string) $this->project->id];
        $this->em()->flush();
    }

    /** @param array<string, string> $errors */
    private function assertRefused(InboxItem $item, InboxActionKind $kind, array $errors, string $note = ''): void
    {
        try {
            $this->runAction($item, $kind, $note);
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame($errors, $e->errors);
        }
    }

    private function assertCancelRefused(InboxAction $action, InboxItem $item, string $key): void
    {
        try {
            ($this->cancelHandler())(new CancelInboxActionCommand($action, $item, $this->project->owner));
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['action' => $key], $e->errors);
        }
    }

    private function runAction(InboxItem $item, InboxActionKind $kind, string $note = ''): ?InboxAction
    {
        return ($this->runHandler())(new RunInboxActionCommand($item, $kind, $note, $this->project->owner));
    }

    /** Built by hand, because nothing injects the handlers yet and the compiler drops an unused private service. */
    private function runHandler(): RunInboxActionHandler
    {
        return new RunInboxActionHandler(
            new ItemActions(
                $this->service(InboxCardWatchRepository::class),
                $this->service(WorkerRunRepository::class),
                $this->service(WorkerRunControls::class),
                $this->service(CardPauses::class),
                new SessionBridges($this->service(BridgeRepository::class), $this->service(BridgeLiveness::class)),
                $this->service(WorkRequestRepository::class),
            ),
            $this->service(RequestBridgeCommandHandler::class),
            $this->service(ReleaseWorkflowPauseHandler::class),
            $this->service(OpenWorkRequestHandler::class),
            $this->service(DeclineInboxItemHandler::class),
            $this->service(WorkerRunRepository::class),
            $this->service(CardDirectory::class),
            $this->em(),
            $this->audit->auditor,
        );
    }

    private function cancelHandler(): CancelInboxActionHandler
    {
        return new CancelInboxActionHandler(
            $this->service(BridgeCommandRepository::class),
            $this->service(CancelBridgeCommandHandler::class),
            $this->service(WithdrawWorkRequestHandler::class),
            $this->em(),
            $this->audit->auditor,
        );
    }

    private function runItem(WorkerRunState $state = WorkerRunState::Blocked): InboxItem
    {
        $run = new WorkerRun(
            project: $this->project,
            bridgeId: $this->bridge->id,
            subjectType: WorkSubject::CARD,
            subjectId: $this->card->id ?? throw new \LogicException('Stored.'),
            cardNumber: $this->card->number,
            workKind: 'implement',
            state: $state,
            runKey: Uuid::v7(),
            sessionId: Uuid::v4(),
        );
        $this->em()->persist($run);
        $this->em()->flush();

        return $this->itemWithWait(static fn (InboxCardWatch $watch): InboxCardWait => new InboxCardWait($watch, InboxCardWaitTrigger::RunBlocked, InboxCardWaitType::WorkerRun, InboxCardWaitReason::Blocked, runId: $run->id));
    }

    private function pauseItem(PauseKind $kind): InboxItem
    {
        $pause = $this->service(PauseCardHandler::class)(new PauseCardCommand($this->card, 'on-hold', 'review-rule', $kind))
            ?? throw new \LogicException('The card had no pause.');

        return $this->itemWithWait(static fn (InboxCardWatch $watch): InboxCardWait => new InboxCardWait($watch, InboxCardWaitTrigger::CardPaused, InboxCardWaitType::CardPause, InboxCardWaitReason::forPause($kind), pauseId: $pause->id));
    }

    /** @param \Closure(InboxCardWatch): InboxCardWait $wait */
    private function itemWithWait(\Closure $wait): InboxItem
    {
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Wait, title: '#1 Ship it', blocking: true);
        $this->em()->persist($item);
        $watch = new InboxCardWatch($item, $this->card->id ?? throw new \LogicException('Stored.'), $this->card->number);
        $this->em()->persist($watch);
        $watch->waits->add($wait($watch));
        $this->em()->flush();

        return $item;
    }

    private function waitOf(InboxItem $item): InboxCardWait
    {
        $watch = $this->service(InboxCardWatchRepository::class)->findOneForItem($item) ?? throw new \LogicException('A wait item has a watch.');

        return $watch->waits->first() ?: throw new \LogicException('The watch has a wait.');
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
