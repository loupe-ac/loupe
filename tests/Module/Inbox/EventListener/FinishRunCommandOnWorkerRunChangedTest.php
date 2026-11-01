<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\EventListener;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\ValueObject\BridgeCommandCause;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\EventListener\FinishRunCommandOnWorkerRunChanged;
use App\Module\Inbox\Repository\InboxActionRepository;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Workflow\WorkflowProjects;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class FinishRunCommandOnWorkerRunChangedTest extends KernelTestCase
{
    use WorkflowProjects;

    private Project $project;
    private InboxCardWatchRepository $inboxCardWatches;
    private InboxActionRepository $inboxActions;
    private Card $card;
    private Bridge $bridge;
    private int $number = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $watches = self::getContainer()->get(InboxCardWatchRepository::class);
        self::assertInstanceOf(InboxCardWatchRepository::class, $watches);
        $this->inboxCardWatches = $watches;
        $actions = self::getContainer()->get(InboxActionRepository::class);
        self::assertInstanceOf(InboxActionRepository::class, $actions);
        $this->inboxActions = $actions;
        $this->project = $this->workflowProject('inbox-finish-command');
        $this->bindLifecycle($this->project);
        $handler = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $handler);
        $this->card = $handler(new CreateCardCommand(project: $this->project, title: 'Card', body: 'Body', type: 'feature', column: $this->column($this->project, 'tech-design'), reporter: Actor::Human));
        $this->bridge = new Bridge($this->project->owner, Uuid::v4(), [], 'b4e39aa7', new \DateTimeImmutable());
        $this->bridge->capabilities = [Bridge::CAPABILITY_COMMANDS];
        $this->em()->persist($this->bridge);
        $this->em()->flush();
    }

    public function test_a_succeeded_command_marks_its_todo_done(): void
    {
        $todo = $this->todo();
        $run = $this->commandRun($todo, WorkerRunState::Succeeded);

        $this->changed($run);

        self::assertSame(InboxItemState::Done, $this->reload($todo)->state);
    }

    public function test_a_second_event_for_the_same_succeeded_run_changes_nothing_more(): void
    {
        $todo = $this->todo();
        $run = $this->commandRun($todo, WorkerRunState::Succeeded);
        $this->changed($run);
        $closedAt = $this->reload($todo)->closedAt;

        $this->changed($run);

        $stored = $this->reload($todo);
        self::assertSame(InboxItemState::Done, $stored->state);
        self::assertEquals($closedAt, $stored->closedAt);
    }

    /** @return iterable<string, array{WorkerRunState}> */
    public static function runsThatDoNotSucceed(): iterable
    {
        yield 'failed' => [WorkerRunState::Failed];
        yield 'running' => [WorkerRunState::Running];
        yield 'not started' => [WorkerRunState::NotStarted];
        yield 'lost' => [WorkerRunState::Lost];
        yield 'timed out' => [WorkerRunState::TimedOut];
    }

    #[DataProvider('runsThatDoNotSucceed')]
    public function test_a_command_that_does_not_succeed_leaves_the_todo_open(WorkerRunState $state): void
    {
        $todo = $this->todo();
        $run = $this->commandRun($todo, $state);

        $this->changed($run);

        self::assertSame(InboxItemState::Open, $this->reload($todo)->state);
    }

    public function test_a_cancelled_action_closes_nothing_even_when_its_run_succeeded(): void
    {
        $todo = $this->todo();
        $run = $this->commandRun($todo, WorkerRunState::Succeeded, cancelled: true);

        $this->changed($run);

        self::assertSame(InboxItemState::Open, $this->reload($todo)->state);
    }

    public function test_a_succeeded_run_of_another_kind_of_action_closes_nothing(): void
    {
        $todo = $this->todo();
        $run = $this->commandRun($todo, WorkerRunState::Succeeded, kind: InboxActionKind::SendAgent);

        $this->changed($run);

        self::assertSame(InboxItemState::Open, $this->reload($todo)->state);
    }

    public function test_an_event_that_names_other_runs_closes_nothing(): void
    {
        $todo = $this->todo();
        $this->commandRun($todo, WorkerRunState::Succeeded);

        $this->listener()(new WorkerRunChanged($this->project->id ?? throw new \LogicException('Stored.'), [], [(string) Uuid::v7()]));

        self::assertSame(InboxItemState::Open, $this->reload($todo)->state);
    }

    public function test_a_succeeded_command_resumes_the_run_a_wait_item_waits_on(): void
    {
        [$item, $stopped] = $this->waitItem();
        $run = $this->commandRun($item, WorkerRunState::Succeeded);

        $this->changed($run);

        $action = $this->actionOf($item);
        $command = $this->em()->find(BridgeCommand::class, $action->bridgeCommandId);
        self::assertInstanceOf(BridgeCommand::class, $command);
        self::assertSame([BridgeCommandKind::ResumeRun, BridgeCommandCause::InboxAction, (string) $stopped->id], [$command->kind, $command->cause, (string) $command->workerRun?->id]);
        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    public function test_a_second_event_does_not_queue_a_second_resume(): void
    {
        [$item] = $this->waitItem();
        $run = $this->commandRun($item, WorkerRunState::Succeeded);
        $this->changed($run);
        $this->changed($run);

        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_commands'));
    }

    public function test_a_failed_command_does_not_resume_a_wait_item(): void
    {
        [$item] = $this->waitItem();
        $run = $this->commandRun($item, WorkerRunState::Failed);

        $this->changed($run);

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_commands'));
        self::assertNull($this->actionOf($item)->bridgeCommandId);
    }

    public function test_a_resume_the_bridge_refuses_leaves_the_item_open_and_does_not_throw(): void
    {
        [$item, $stopped] = $this->waitItem();
        $stopped->state = WorkerRunState::Running;
        $this->em()->flush();
        $run = $this->commandRun($item, WorkerRunState::Succeeded);

        $this->changed($run);

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_commands'));
        self::assertNull($this->actionOf($item)->bridgeCommandId);
        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    private function changed(WorkerRun $run): void
    {
        $this->listener()(new WorkerRunChanged($this->project->id ?? throw new \LogicException('Stored.'), [], [(string) $run->id]));
    }

    private function listener(): FinishRunCommandOnWorkerRunChanged
    {
        $listener = self::getContainer()->get(FinishRunCommandOnWorkerRunChanged::class);
        self::assertInstanceOf(FinishRunCommandOnWorkerRunChanged::class, $listener);

        return $listener;
    }

    private function todo(): InboxItem
    {
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Todo, title: 'Export it', blocking: false, origin: InboxAskOrigin::Agent);
        $this->em()->persist($item);
        $this->em()->flush();

        return $item;
    }

    /** @return array{InboxItem, WorkerRun} the wait item and the stopped run it waits on */
    private function waitItem(): array
    {
        $stopped = new WorkerRun(
            project: $this->project,
            bridgeId: $this->bridge->id,
            subjectType: WorkSubject::CARD,
            subjectId: $this->card->id ?? throw new \LogicException('Stored.'),
            cardNumber: $this->card->number,
            workKind: 'implement',
            state: WorkerRunState::Blocked,
            runKey: Uuid::v7(),
            sessionId: Uuid::v4(),
        );
        $this->em()->persist($stopped);
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Wait, title: '#1 Ship it', blocking: true, origin: InboxAskOrigin::Loupe);
        $this->em()->persist($item);
        $watch = new InboxCardWatch($item, $this->card->id ?? throw new \LogicException('Stored.'), $this->card->number);
        $this->em()->persist($watch);
        $this->em()->flush();
        $watch->waits->add(new InboxCardWait($watch, 'run-needs-you', InboxCardWaitType::WorkerRun, 'marker.run_needs_you', runId: $stopped->id));
        $this->em()->flush();

        return [$item, $stopped];
    }

    /** Records the action, its request and the run the request started. */
    private function commandRun(InboxItem $item, WorkerRunState $state, WorkRequestState $requestState = WorkRequestState::Done, bool $cancelled = false, InboxActionKind $kind = InboxActionKind::RunCommand): WorkerRun
    {
        $request = new WorkRequest($this->project, WorkSubject::CARD, $this->card->id ?? throw new \LogicException('Stored.'), $this->card->number, 'design-export', Bridge::CAPABILITY_RUN_COMMAND, 'inbox:run-command', new \DateTimeImmutable());
        $request->state = $requestState;
        $this->em()->persist($request);
        $this->em()->flush();
        $action = new InboxAction($item, $kind, $this->project->owner);
        $action->workRequestId = $request->id;
        $action->cancelledAt = $cancelled ? new \DateTimeImmutable() : null;
        if (InboxItemKind::Wait === $item->kind) {
            $action->waitId = $this->watchOf($item)->waits->first()->id ?? null;
        }
        $this->em()->persist($action);
        $run = new WorkerRun(
            project: $this->project,
            bridgeId: $this->bridge->id,
            subjectType: WorkSubject::CARD,
            subjectId: $this->card->id,
            cardNumber: $this->card->number,
            workKind: 'design-export',
            state: $state,
            runKey: Uuid::v7(),
            workRequestId: $request->id,
        );
        $this->em()->persist($run);
        $this->em()->flush();

        return $run;
    }

    private function watchOf(InboxItem $item): InboxCardWatch
    {
        return $this->inboxCardWatches->findOneBy(['item' => $item]) ?? throw new \LogicException('A wait item has a watch.');
    }

    private function actionOf(InboxItem $item): InboxAction
    {
        $this->em()->clear();

        return $this->inboxActions->findOneBy(['item' => $item->id]) ?? throw new \LogicException('The item has an action.');
    }

    private function reload(InboxItem $item): InboxItem
    {
        $this->em()->clear();

        return $this->em()->find(InboxItem::class, $item->id) ?? throw new \LogicException('Stored.');
    }
}
