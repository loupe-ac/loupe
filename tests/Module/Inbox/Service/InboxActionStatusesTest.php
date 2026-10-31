<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\BridgeLiveness;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Repository\InboxActionRepository;
use App\Module\Inbox\Service\InboxActionStatuses;
use App\Module\Inbox\Service\SessionBridges;
use App\Module\Inbox\View\InboxActionState;
use App\Module\Inbox\View\InboxActionStatus;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class InboxActionStatusesTest extends KernelTestCase
{
    use InboxFixtures;

    private const string CLICK = '2026-10-13 12:00:00';

    private EntityManagerInterface $em;
    private User $owner;
    private Project $project;
    private InboxItem $item;
    private Bridge $bridge;
    private Uuid $sessionCard;
    private int $sessions = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->owner = $this->owner($em, 'statuses');
        $this->project = $this->project($em, $this->owner, 'statuses');
        $this->item = $this->item($em, $this->project, 1);
        $this->sessionCard = Uuid::v4();
        $this->bridge = new Bridge($this->owner, Uuid::v4(), [], 'b4e39aa7', new \DateTimeImmutable());
        $this->em->persist($this->bridge);
        $this->em->flush();
    }

    public function test_a_pending_command_reads_queued_and_can_be_cancelled(): void
    {
        $this->resume(BridgeCommandState::Pending);

        $status = $this->onlyStatus();

        self::assertSame(InboxActionState::Queued, $status->state);
        self::assertSame('inbox.action.status.queued', $status->labelKey);
        self::assertTrue($status->cancellable);
        self::assertFalse($status->offline);
        self::assertSame(self::CLICK, $status->at?->format('Y-m-d H:i:s'));
    }

    public function test_a_pending_command_on_a_quiet_bridge_reads_offline(): void
    {
        $this->bridge->lastSeenAt = new \DateTimeImmutable('-1 day');
        $this->resume(BridgeCommandState::Pending);

        $status = $this->onlyStatus();

        self::assertSame(InboxActionState::Queued, $status->state);
        self::assertTrue($status->offline);
    }

    public function test_an_expired_command_reads_expired(): void
    {
        $this->resume(BridgeCommandState::Expired, settledAt: '2026-10-13 12:15:00');

        $status = $this->onlyStatus();

        self::assertSame(InboxActionState::Expired, $status->state);
        self::assertSame('inbox.action.status.expired', $status->labelKey);
        self::assertFalse($status->cancellable);
        self::assertSame('2026-10-13 12:15:00', $status->at?->format('Y-m-d H:i:s'));
    }

    public function test_a_refused_command_carries_its_reason_when_it_has_one(): void
    {
        $this->resume(BridgeCommandState::Refused, reason: 'No session on this machine.');

        $status = $this->onlyStatus();

        self::assertSame(InboxActionState::Refused, $status->state);
        self::assertSame('inbox.action.status.refused', $status->labelKey);
        self::assertSame(['%reason%' => 'No session on this machine.'], $status->labelParameters);
    }

    public function test_a_refused_command_without_a_reason_has_no_parameter(): void
    {
        $this->resume(BridgeCommandState::Refused);

        $status = $this->onlyStatus();

        self::assertSame('inbox.action.status.refused_no_reason', $status->labelKey);
        self::assertSame([], $status->labelParameters);
    }

    public function test_a_cancelled_command_or_a_marked_action_reads_cancelled(): void
    {
        $this->resume(BridgeCommandState::Cancelled);
        $marked = $this->resume(BridgeCommandState::Pending);
        $marked->cancelledAt = new \DateTimeImmutable('2026-10-13 12:05:00');
        $this->em->flush();

        $statuses = $this->statuses();

        self::assertSame([InboxActionState::Cancelled, InboxActionState::Cancelled], array_map(static fn (InboxActionStatus $s): InboxActionState => $s->state, $statuses));
        self::assertSame([false, false], array_map(static fn (InboxActionStatus $s): bool => $s->cancellable, $statuses));
        self::assertSame('2026-10-13 12:05:00', $statuses[1]->at?->format('Y-m-d H:i:s'));
    }

    public function test_a_done_command_with_no_continuing_run_reads_resumed(): void
    {
        $this->resume(BridgeCommandState::Done);

        $status = $this->onlyStatus();

        self::assertSame(InboxActionState::Resumed, $status->state);
        self::assertSame('inbox.action.status.resumed', $status->labelKey);
    }

    public function test_a_done_command_reads_the_state_of_the_newest_run_that_continues_the_stopped_run(): void
    {
        $stopped = $this->seedRun(WorkerRunState::Blocked);
        $this->resume(BridgeCommandState::Done, run: $stopped);
        $this->seedRun(WorkerRunState::Failed, continues: $stopped, receivedAt: '2026-10-13 12:01:00');
        $this->seedRun(WorkerRunState::Running, continues: $stopped, receivedAt: '2026-10-13 12:02:00');

        $status = $this->onlyStatus();

        self::assertSame(InboxActionState::Running, $status->state);
        self::assertSame('bridge.worker_runs.state.running', $status->labelKey);
        self::assertSame('2026-10-13 12:02:00', $status->at?->format('Y-m-d H:i:s'));
    }

    /** An earlier resume of the same run made that run, and this click did not. */
    public function test_a_run_that_reported_before_the_click_is_not_the_continuation(): void
    {
        $stopped = $this->seedRun(WorkerRunState::Blocked);
        $this->resume(BridgeCommandState::Done, run: $stopped);
        $this->seedRun(WorkerRunState::Succeeded, continues: $stopped, receivedAt: '2026-10-13 11:00:00');

        self::assertSame(InboxActionState::Resumed, $this->onlyStatus()->state);
    }

    public function test_a_retry_reads_retried_at_the_time_of_the_click(): void
    {
        $action = new InboxAction($this->item, InboxActionKind::RetryCard, $this->owner, '', new \DateTimeImmutable(self::CLICK));
        $action->pauseId = Uuid::v4();
        $this->em->persist($action);
        $this->em->flush();

        $status = $this->onlyStatus();

        self::assertSame(InboxActionState::Retried, $status->state);
        self::assertSame('inbox.action.status.retried', $status->labelKey);
        self::assertFalse($status->cancellable);
        self::assertSame(self::CLICK, $status->at?->format('Y-m-d H:i:s'));
    }

    public function test_an_action_with_no_line_is_left_out_and_an_item_with_no_action_has_an_empty_list(): void
    {
        $this->em->persist(new InboxAction($this->item, InboxActionKind::Open, $this->owner));
        $this->em->persist(new InboxAction($this->item, InboxActionKind::ResumeRun, $this->owner));
        $empty = $this->item($this->em, $this->project, 2);
        $this->em->flush();

        $all = $this->service()->forItems([$this->item, $empty]);

        self::assertSame([(string) $this->item->id => [], (string) $empty->id => []], $all);
    }

    public function test_statuses_come_back_per_item_oldest_action_first(): void
    {
        $other = $this->item($this->em, $this->project, 2);
        $first = $this->resume(BridgeCommandState::Expired, clickedAt: '2026-10-13 09:00:00');
        $second = $this->resume(BridgeCommandState::Pending, clickedAt: '2026-10-13 10:00:00');
        $third = $this->resume(BridgeCommandState::Pending, item: $other);

        $all = $this->service()->forItems([$this->item, $other]);

        self::assertSame([$first, $second], array_map(static fn (InboxActionStatus $s): InboxAction => $s->action, $all[(string) $this->item->id]));
        self::assertSame([$third], array_map(static fn (InboxActionStatus $s): InboxAction => $s->action, $all[(string) $other->id]));
    }

    public function test_an_open_request_reads_queued_and_cancellable_while_a_bridge_follows_the_project(): void
    {
        $this->followingBridge();
        $this->sendAgent(WorkRequestState::Open);

        $status = $this->onlyStatus();

        self::assertSame([InboxActionState::Queued, 'inbox.action.status.queued', true, false], [$status->state, $status->labelKey, $status->cancellable, $status->offline]);
        self::assertSame(self::CLICK, $status->at?->format('Y-m-d H:i:s'));
    }

    public function test_an_open_request_reads_offline_when_the_following_bridge_is_quiet(): void
    {
        $this->followingBridge()->lastSeenAt = new \DateTimeImmutable('-1 day');
        $this->sendAgent(WorkRequestState::Open);

        self::assertTrue($this->onlyStatus()->offline);
    }

    public function test_an_open_request_reads_offline_when_the_following_bridge_takes_no_work_requests(): void
    {
        $this->followingBridge(capabilities: []);
        $this->sendAgent(WorkRequestState::Open);

        self::assertTrue($this->onlyStatus()->offline);
    }

    public function test_a_claimed_request_with_no_run_yet_reads_preparing(): void
    {
        $this->followingBridge();
        $this->sendAgent(WorkRequestState::Claimed);

        $status = $this->onlyStatus();

        self::assertSame([InboxActionState::Preparing, 'inbox.action.status.preparing', false], [$status->state, $status->labelKey, $status->cancellable]);
    }

    public function test_a_done_request_with_no_run_yet_reads_preparing(): void
    {
        $this->sendAgent(WorkRequestState::Done);

        self::assertSame(InboxActionState::Preparing, $this->onlyStatus()->state);
    }

    public function test_a_done_request_reads_the_state_of_the_newest_run_it_started(): void
    {
        $request = $this->sendAgent(WorkRequestState::Done);
        $this->seedRun(WorkerRunState::Running, receivedAt: '2026-10-13 12:01:00', workRequestId: $request->id);
        $this->seedRun(WorkerRunState::Succeeded, receivedAt: '2026-10-13 12:05:00', workRequestId: $request->id);
        $this->seedRun(WorkerRunState::Failed, receivedAt: '2026-10-13 12:09:00');

        $status = $this->onlyStatus();

        self::assertSame(InboxActionState::Running, $status->state);
        self::assertSame('bridge.worker_runs.state.succeeded', $status->labelKey);
        self::assertSame('2026-10-13 12:05:00', $status->at?->format('Y-m-d H:i:s'));
    }

    public function test_an_expired_refused_or_cancelled_request_reads_as_such(): void
    {
        $this->sendAgent(WorkRequestState::Expired, settledAt: '2026-10-13 14:00:00');
        $this->sendAgent(WorkRequestState::Refused, reason: 'no-repository');
        $this->sendAgent(WorkRequestState::Refused);
        $this->sendAgent(WorkRequestState::Cancelled);

        $statuses = $this->statuses();

        self::assertSame(
            [InboxActionState::Expired, InboxActionState::Refused, InboxActionState::Refused, InboxActionState::Cancelled],
            array_map(static fn (InboxActionStatus $s): InboxActionState => $s->state, $statuses),
        );
        self::assertSame('2026-10-13 14:00:00', $statuses[0]->at?->format('Y-m-d H:i:s'));
        self::assertSame(['inbox.action.status.refused', ['%reason%' => 'no-repository']], [$statuses[1]->labelKey, $statuses[1]->labelParameters]);
        self::assertSame('inbox.action.status.refused_no_reason', $statuses[2]->labelKey);
        self::assertSame([false, false, false, false], array_map(static fn (InboxActionStatus $s): bool => $s->cancellable, $statuses));
    }

    public function test_a_marked_action_reads_cancelled_even_when_a_bridge_claimed_the_request_first(): void
    {
        $this->sendAgent(WorkRequestState::Claimed, cancelledAt: '2026-10-13 12:05:00');

        self::assertSame(InboxActionState::Cancelled, $this->onlyStatus()->state);
    }

    public function test_a_send_agent_that_never_got_its_request_has_no_line(): void
    {
        $this->em->persist(new InboxAction($this->item, InboxActionKind::SendAgent, $this->owner));
        $this->em->flush();

        self::assertSame([], $this->statuses());
    }

    public function test_an_open_session_request_reads_queued_and_can_be_cancelled(): void
    {
        $this->session(WorkRequestState::Open);

        $status = $this->onlyStatus();

        self::assertSame(InboxActionState::Queued, $status->state);
        self::assertSame('inbox.action.status.queued', $status->labelKey);
        self::assertTrue($status->cancellable);
        self::assertTrue($status->offline);
        self::assertSame('2026-10-13 12:01:00', $status->at?->format('Y-m-d H:i:s'));
    }

    public function test_an_open_session_request_is_online_while_a_live_bridge_reports_session(): void
    {
        $this->bridge->capabilities = [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_SESSION];
        $this->bridge->projects = [(string) $this->project->id];
        $this->session(WorkRequestState::Open);

        self::assertFalse($this->onlyStatus()->offline);
    }

    public function test_a_claimed_or_done_session_request_with_no_run_reads_preparing(): void
    {
        $claimed = $this->session(WorkRequestState::Claimed);
        $done = $this->session(WorkRequestState::Done);

        $statuses = $this->statuses();

        self::assertSame([(string) $claimed->id, (string) $done->id], array_map(static fn (InboxActionStatus $s): string => (string) $s->action->id, $statuses));
        foreach ($statuses as $status) {
            self::assertSame([InboxActionState::Preparing, 'inbox.action.status.preparing', false], [$status->state, $status->labelKey, $status->cancellable]);
        }
    }

    public function test_an_expired_refused_or_cancelled_session_request_reads_as_such(): void
    {
        $this->session(WorkRequestState::Expired);
        $this->session(WorkRequestState::Refused, reason: 'launch-failed');
        $this->session(WorkRequestState::Refused);
        $this->session(WorkRequestState::Cancelled);

        $statuses = $this->statuses();

        self::assertSame(
            [
                [InboxActionState::Expired, 'inbox.action.status.expired', []],
                [InboxActionState::Refused, 'inbox.action.status.refused', ['%reason%' => 'launch-failed']],
                [InboxActionState::Refused, 'inbox.action.status.refused_no_reason', []],
                [InboxActionState::Cancelled, 'inbox.action.status.cancelled', []],
            ],
            array_map(static fn (InboxActionStatus $s): array => [$s->state, $s->labelKey, $s->labelParameters], $statuses),
        );
    }

    public function test_a_session_action_marked_cancelled_reads_cancelled_even_when_the_request_is_open(): void
    {
        $action = $this->session(WorkRequestState::Open);
        $action->cancelledAt = new \DateTimeImmutable('2026-10-13 12:01:30');
        $this->em->flush();

        $status = $this->onlyStatus();

        self::assertSame([InboxActionState::Cancelled, false, '2026-10-13 12:01:30'], [$status->state, $status->cancellable, $status->at?->format('Y-m-d H:i:s')]);
    }

    public function test_a_session_request_with_a_run_reads_the_state_of_its_newest_run(): void
    {
        $action = $this->session(WorkRequestState::Done);
        $this->seedRun(WorkerRunState::Failed, receivedAt: '2026-10-13 12:01:00', workRequestId: $action->workRequestId);
        $this->seedRun(WorkerRunState::Running, receivedAt: '2026-10-13 12:02:00', workRequestId: $action->workRequestId);
        $this->seedRun(WorkerRunState::Succeeded, receivedAt: '2026-10-13 12:03:00', workRequestId: Uuid::v4());

        $status = $this->onlyStatus();

        self::assertSame([InboxActionState::Running, 'bridge.worker_runs.state.running', '2026-10-13 12:02:00'], [$status->state, $status->labelKey, $status->at?->format('Y-m-d H:i:s')]);
    }

    public function test_an_item_session_reads_offline_while_the_live_bridge_lacks_the_item_work_kind(): void
    {
        $this->bridge->capabilities = [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_SESSION];
        $this->bridge->projects = [(string) $this->project->id];
        $this->session(WorkRequestState::Open, aboutItem: true);

        self::assertTrue($this->onlyStatus()->offline);
    }

    public function test_an_item_session_reads_online_while_a_live_bridge_reports_the_item_work_kind(): void
    {
        $this->bridge->capabilities = [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_SESSION];
        $this->bridge->workKinds = [Bridge::workKindOf(InboxItem::SUBJECT_TYPE, WorkRequest::SESSION_KIND)];
        $this->bridge->projects = [(string) $this->project->id];
        $this->session(WorkRequestState::Open, aboutItem: true);

        self::assertFalse($this->onlyStatus()->offline);
    }

    public function test_an_item_session_with_a_run_reads_the_state_of_the_run(): void
    {
        $action = $this->session(WorkRequestState::Done, aboutItem: true);
        $run = new WorkerRun(
            project: $this->project,
            bridgeId: $this->bridge->id,
            subjectType: InboxItem::SUBJECT_TYPE,
            subjectId: $this->item->id ?? throw new \LogicException('Stored.'),
            cardNumber: null,
            workKind: 'session',
            state: WorkerRunState::Running,
            sessionId: Uuid::v4(),
            receivedAt: new \DateTimeImmutable('2026-10-13 12:02:00'),
            kind: WorkerRunKind::Interactive,
            workRequestId: $action->workRequestId,
        );
        $this->em->persist($run);
        $this->em->flush();

        $status = $this->onlyStatus();

        self::assertSame([InboxActionState::Running, 'bridge.worker_runs.state.running', '2026-10-13 12:02:00'], [$status->state, $status->labelKey, $status->at?->format('Y-m-d H:i:s')]);
    }

    public function test_a_session_action_with_no_request_has_no_line(): void
    {
        $this->em->persist(new InboxAction($this->item, InboxActionKind::StartSession, $this->owner));
        $this->em->flush();

        self::assertSame([], $this->statuses());
    }

    public function test_an_open_add_reads_queued_and_cancellable_while_a_bridge_follows_the_project(): void
    {
        $this->followingBridge();
        $this->addEntry(WorkRequestState::Open);

        $status = $this->onlyStatus();

        self::assertSame([InboxActionState::Queued, 'inbox.action.status.queued', true, false], [$status->state, $status->labelKey, $status->cancellable, $status->offline]);
    }

    public function test_an_open_add_reads_offline_when_no_bridge_is_online(): void
    {
        $this->followingBridge()->lastSeenAt = new \DateTimeImmutable('-1 day');
        $this->addEntry(WorkRequestState::Open);

        self::assertTrue($this->onlyStatus()->offline);
    }

    public function test_a_claimed_add_reads_preparing(): void
    {
        $this->addEntry(WorkRequestState::Claimed);

        self::assertSame([InboxActionState::Preparing, false], [$this->onlyStatus()->state, $this->onlyStatus()->cancellable]);
    }

    public function test_a_done_add_reads_added_and_answers_nothing(): void
    {
        $this->addEntry(WorkRequestState::Done, settledAt: '2026-10-13 12:03:00');

        $status = $this->onlyStatus();

        self::assertSame([InboxActionState::Added, 'inbox.action.status.added', false], [$status->state, $status->labelKey, $status->cancellable]);
        self::assertSame('2026-10-13 12:03:00', $status->at?->format('Y-m-d H:i:s'));
        self::assertFalse($status->state->inProgress());
    }

    public function test_a_refused_add_carries_its_reason_code(): void
    {
        $this->addEntry(WorkRequestState::Refused, reason: 'entry-exists');
        $this->addEntry(WorkRequestState::Refused);

        $statuses = $this->statuses();

        self::assertSame([InboxActionState::Refused, 'inbox.action.status.refused', ['%reason%' => 'entry-exists']], [$statuses[0]->state, $statuses[0]->labelKey, $statuses[0]->labelParameters]);
        self::assertSame('inbox.action.status.refused_no_reason', $statuses[1]->labelKey);
    }

    public function test_an_expired_or_cancelled_add_reads_as_such_and_a_marked_one_reads_cancelled(): void
    {
        $this->addEntry(WorkRequestState::Expired, settledAt: '2026-10-13 14:00:00');
        $this->addEntry(WorkRequestState::Cancelled);
        $this->addEntry(WorkRequestState::Open, cancelledAt: '2026-10-13 12:05:00');

        self::assertSame(
            [InboxActionState::Expired, InboxActionState::Cancelled, InboxActionState::Cancelled],
            array_map(static fn (InboxActionStatus $s): InboxActionState => $s->state, $this->statuses()),
        );
    }

    public function test_an_add_that_never_got_its_request_has_no_line(): void
    {
        $this->em->persist(new InboxAction($this->item, InboxActionKind::AddEntry, $this->owner));
        $this->em->flush();

        self::assertSame([], $this->statuses());
    }

    public function test_a_send_agent_and_an_add_on_one_item_each_read_their_own_request(): void
    {
        $this->followingBridge();
        $this->sendAgent(WorkRequestState::Open);
        $this->addEntry(WorkRequestState::Done);

        self::assertSame([InboxActionKind::SendAgent, InboxActionKind::AddEntry], array_map(static fn (InboxActionStatus $s): InboxActionKind => $s->action->kind, $this->statuses()));
    }

    private function onlyStatus(): InboxActionStatus
    {
        $statuses = $this->statuses();
        self::assertCount(1, $statuses);

        return $statuses[0];
    }

    /** @return list<InboxActionStatus> */
    private function statuses(): array
    {
        $this->em->clear();
        $item = $this->em->find(InboxItem::class, $this->item->id) ?? throw new \LogicException('Stored.');

        return $this->service()->forItems([$item])[(string) $this->item->id] ?? [];
    }

    private function resume(
        BridgeCommandState $state,
        ?string $reason = null,
        ?string $settledAt = null,
        string $clickedAt = self::CLICK,
        ?WorkerRun $run = null,
        ?InboxItem $item = null,
    ): InboxAction {
        $run ??= $this->seedRun(WorkerRunState::Blocked);
        $requestedAt = new \DateTimeImmutable($clickedAt);
        $command = new BridgeCommand($this->owner, $this->bridge->id, $this->project, $run, BridgeCommandKind::ResumeRun, $this->owner, $requestedAt, $requestedAt->modify('+15 minutes'));
        $command->state = $state;
        $command->reason = $reason;
        $command->settledAt = null === $settledAt ? null : new \DateTimeImmutable($settledAt);
        $this->em->persist($command);
        $this->em->flush();

        $action = new InboxAction($item ?? $this->item, InboxActionKind::ResumeRun, $this->owner, '', $requestedAt);
        $action->bridgeCommandId = $command->id;
        $this->em->persist($action);
        $this->em->flush();

        return $action;
    }

    /** @param list<string> $capabilities */
    private function followingBridge(array $capabilities = [Bridge::CAPABILITY_WORK_REQUESTS]): Bridge
    {
        $bridge = new Bridge($this->owner, Uuid::v4(), [(string) $this->project->id], 'b4e39aa7', new \DateTimeImmutable());
        $bridge->capabilities = $capabilities;
        $this->em->persist($bridge);
        $this->em->flush();

        return $bridge;
    }

    private function sendAgent(WorkRequestState $state, ?string $reason = null, ?string $settledAt = null, ?string $cancelledAt = null): WorkRequest
    {
        $requestedAt = new \DateTimeImmutable(self::CLICK);
        $request = new WorkRequest($this->project, WorkSubject::CARD, Uuid::v4(), 7, 'tech-design', null, 'inbox:send-agent', $requestedAt);
        $request->state = $state;
        $request->reason = $reason;
        $request->settledAt = null === $settledAt ? null : new \DateTimeImmutable($settledAt);
        $this->em->persist($request);
        $this->em->flush();

        $action = new InboxAction($this->item, InboxActionKind::SendAgent, $this->owner, '', $requestedAt);
        $action->workRequestId = $request->id;
        $action->cancelledAt = null === $cancelledAt ? null : new \DateTimeImmutable($cancelledAt);
        $this->em->persist($action);
        $this->em->flush();

        return $request;
    }

    private function addEntry(WorkRequestState $state, ?string $reason = null, ?string $settledAt = null, ?string $cancelledAt = null): WorkRequest
    {
        $requestedAt = new \DateTimeImmutable(self::CLICK)->modify(\sprintf('+%d minutes', ++$this->sessions));
        $request = new WorkRequest($this->project, InboxItem::SUBJECT_TYPE, $this->item->id ?? throw new \LogicException('Stored.'), null, 'add-entry', Bridge::CAPABILITY_ADD_ENTRY, 'inbox:add-entry', $requestedAt);
        $request->state = $state;
        $request->reason = $reason;
        $request->settledAt = null === $settledAt ? null : new \DateTimeImmutable($settledAt);
        $this->em->persist($request);
        $this->em->flush();

        $action = new InboxAction($this->item, InboxActionKind::AddEntry, $this->owner, '', $requestedAt);
        $action->workRequestId = $request->id;
        $action->cancelledAt = null === $cancelledAt ? null : new \DateTimeImmutable($cancelledAt);
        $this->em->persist($action);
        $this->em->flush();

        return $request;
    }

    private function session(WorkRequestState $state, ?string $reason = null, bool $aboutItem = false): InboxAction
    {
        $clickedAt = new \DateTimeImmutable(self::CLICK)->modify(\sprintf('+%d minutes', ++$this->sessions));
        $request = $aboutItem
            ? new WorkRequest($this->project, InboxItem::SUBJECT_TYPE, $this->item->id ?? throw new \LogicException('Stored.'), null, WorkRequest::SESSION_KIND, Bridge::CAPABILITY_SESSION, 'inbox:start-session', $clickedAt)
            : new WorkRequest($this->project, WorkSubject::CARD, $this->sessionCard, 1, WorkRequest::SESSION_KIND, Bridge::CAPABILITY_SESSION, 'inbox:start-session', $clickedAt);
        $request->state = $state;
        $request->reason = $reason;
        $this->em->persist($request);
        $this->em->flush();

        $action = new InboxAction($this->item, InboxActionKind::StartSession, $this->owner, '', $clickedAt);
        $action->workRequestId = $request->id;
        $this->em->persist($action);
        $this->em->flush();

        return $action;
    }

    private function seedRun(WorkerRunState $state, ?WorkerRun $continues = null, string $receivedAt = '2026-10-13 10:00:00', ?Uuid $workRequestId = null): WorkerRun
    {
        $run = new WorkerRun(
            project: $this->project,
            bridgeId: $this->bridge->id,
            subjectType: WorkSubject::CARD,
            subjectId: $workRequestId instanceof Uuid ? $this->sessionCard : Uuid::v4(),
            cardNumber: 1,
            workKind: 'implement',
            state: $state,
            runKey: Uuid::v7(),
            sessionId: Uuid::v4(),
            receivedAt: new \DateTimeImmutable($receivedAt),
            continuesRun: $continues,
            workRequestId: $workRequestId,
        );
        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    /** Built by hand, because nothing injects the service yet and the compiler drops an unused private service. */
    private function service(): InboxActionStatuses
    {
        return new InboxActionStatuses(
            $this->get(InboxActionRepository::class),
            $this->get(BridgeCommandRepository::class),
            $this->get(WorkerRunRepository::class),
            $this->get(WorkRequestRepository::class),
            $this->get(BridgeRepository::class),
            $this->get(BridgeLiveness::class),
            new SessionBridges($this->get(BridgeRepository::class), $this->get(BridgeLiveness::class)),
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function get(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
