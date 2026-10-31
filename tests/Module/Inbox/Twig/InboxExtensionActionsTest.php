<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Twig;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Inbox\Service\InboxActionStatuses;
use App\Module\Inbox\Twig\InboxExtension;
use App\Module\Inbox\View\InboxActionState;
use App\Module\Inbox\View\InboxActionStatus;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

/** The Twig functions that give the thread its split button and its status lines. */
final class InboxExtensionActionsTest extends KernelTestCase
{
    use WorkflowProjects;

    private Project $project;
    private Card $card;
    private Bridge $bridge;
    private InboxExtension $extension;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->project = $this->workflowProject('inbox-twig-actions');
        $this->bindLifecycle($this->project);
        $this->card = self::getContainer()->get(CreateCardHandler::class)(new CreateCardCommand(
            project: $this->project,
            title: 'Card',
            body: 'Body',
            type: 'feature',
            column: $this->column($this->project, 'in-progress'),
            reporter: Actor::Human,
        ));
        $this->bridge = new Bridge($this->project->owner, Uuid::v4(), [], 'b4e39aa7', new \DateTimeImmutable());
        $this->bridge->capabilities = [Bridge::CAPABILITY_COMMANDS];
        $this->em()->persist($this->bridge);
        $this->em()->flush();
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $this->extension = $twig->getExtension(InboxExtension::class);
    }

    public function test_the_default_is_the_main_part_and_dismiss_sits_apart(): void
    {
        $item = $this->runItem(WorkerRunState::Blocked);

        $actions = $this->extension->actions($item);

        self::assertSame(InboxActionKind::ResumeRun, $actions->main?->action->kind);
        self::assertNull($actions->main->href);
        self::assertSame([InboxActionKind::SendAgent, InboxActionKind::Open, InboxActionKind::StartSession], array_map(static fn ($row) => $row->action->kind, $actions->menu));
        self::assertNull($actions->menu[0]->href);
        self::assertSame('/projects/'.$this->project->id.'/board/cards/'.$this->card->id, $actions->menu[1]->href);
        self::assertTrue($actions->menu[1]->card);
        self::assertSame(InboxActionKind::Dismiss, $actions->dismiss?->action->kind);
    }

    public function test_the_thread_function_builds_the_thread_of_the_item(): void
    {
        $item = $this->runItem(WorkerRunState::Blocked);

        $thread = $this->extension->thread($item);

        self::assertSame($item, $thread->item);
        self::assertSame(InboxActionKind::ResumeRun, $thread->replyCard?->main?->action->kind);
        self::assertNotNull(array_find(self::getContainer()->get(Environment::class)->getFunctions(), static fn ($function): bool => 'inbox_thread' === $function->getName()));
    }

    public function test_the_card_page_gets_no_link_back_to_itself(): void
    {
        $item = $this->runItem(WorkerRunState::Blocked);

        $actions = $this->extension->actions($item, (string) $this->card->id);

        self::assertSame([InboxActionKind::SendAgent, InboxActionKind::StartSession], array_map(static fn ($row) => $row->action->kind, $actions->menu));
        self::assertSame(InboxActionKind::ResumeRun, $actions->main?->action->kind);
    }

    public function test_an_item_with_no_resume_defaults_to_open_as_a_link(): void
    {
        $item = $this->runItem(WorkerRunState::Running);

        $actions = $this->extension->actions($item);

        self::assertSame(InboxActionKind::Open, $actions->main?->action->kind);
        self::assertTrue($actions->main->isLink());
        self::assertSame([InboxActionKind::StartSession], array_map(static fn ($row) => $row->action->kind, $actions->menu));
        self::assertSame(InboxActionKind::Dismiss, $actions->dismiss?->action->kind);
    }

    public function test_a_disabled_action_stays_in_the_menu_and_never_takes_the_main_part(): void
    {
        $item = $this->runItem(WorkerRunState::Running);

        $actions = $this->extension->actions($item, (string) $this->card->id);

        self::assertNull($actions->main);
        self::assertSame([InboxActionKind::StartSession], array_map(static fn ($row) => $row->action->kind, $actions->menu));
        self::assertSame('inbox.action.start-session.disabled', $actions->menu[0]->action->disabledReasonKey);
        self::assertSame(InboxActionKind::Dismiss, $actions->dismiss?->action->kind);
        self::assertFalse($actions->isEmpty());
    }

    public function test_start_session_takes_the_main_part_when_a_session_bridge_is_online_and_nothing_else_fits(): void
    {
        $session = new Bridge($this->project->owner, Uuid::v4(), [$this->project->id?->toRfc4122() ?? ''], 'b4e39aa7', new \DateTimeImmutable());
        $session->capabilities = [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_SESSION];
        $this->em()->persist($session);
        $this->em()->flush();
        $item = $this->runItem(WorkerRunState::Running);

        $actions = $this->extension->actions($item, (string) $this->card->id);

        self::assertSame(InboxActionKind::StartSession, $actions->main?->action->kind);
        self::assertNull($actions->main->action->disabledReasonKey);
        self::assertSame([], $actions->menu);
    }

    public function test_the_statuses_are_the_lines_of_the_item_oldest_first(): void
    {
        $item = $this->runItem(WorkerRunState::Blocked);

        $retried = new InboxAction($item, InboxActionKind::RetryCard, $this->project->owner, 'Again');
        $this->em()->persist($retried);
        $this->em()->flush();

        $lines = $this->statusLines($item);

        self::assertCount(1, $lines);
        self::assertSame(InboxActionState::Retried, $lines[0]->state);
        self::assertSame($retried, $lines[0]->action);
        self::assertTrue($lines[0]->state->inProgress());
    }

    public function test_an_item_with_no_action_has_no_status_line(): void
    {
        $item = $this->runItem(WorkerRunState::Blocked);

        self::assertSame([], $this->statusLines($item));
    }

    public function test_only_the_states_that_still_answer_the_wait_are_in_progress(): void
    {
        $running = array_filter(InboxActionState::cases(), static fn (InboxActionState $state): bool => $state->inProgress());

        self::assertSame(
            [InboxActionState::Queued, InboxActionState::Resumed, InboxActionState::Preparing, InboxActionState::Retried, InboxActionState::Running],
            array_values($running),
        );
    }

    public function test_a_wait_that_opens_in_the_second_of_the_click_brings_the_reply_back(): void
    {
        $item = $this->runItem(WorkerRunState::Blocked);
        $watch = $this->watchOf($item);
        $answered = $watch->waits->first() ?: throw new \LogicException('A run item has a wait.');
        $second = new \DateTimeImmutable('2026-10-14 01:00:00');

        $action = new InboxAction($item, InboxActionKind::RetryCard, $this->project->owner, '', $second);
        $action->waitId = $answered->id;
        $this->em()->persist($action);
        $this->em()->flush();
        $status = $this->statusLines($item)[0];

        self::assertTrue($status->answers($watch->waits));

        $answered->endedAt = $second;
        $watch->waits->add(new InboxCardWait($watch, 'run-needs-you', InboxCardWaitType::WorkerRun, 'marker.run_needs_you', runId: Uuid::v7(), startedAt: $second));
        $this->em()->flush();

        self::assertFalse($status->answers($watch->waits));
    }

    public function test_an_older_open_wait_keeps_the_reply_after_the_answered_wait_ends(): void
    {
        $item = $this->runItem(WorkerRunState::Blocked);
        $watch = $this->watchOf($item);
        $answered = $watch->waits->first() ?: throw new \LogicException('A run item has a wait.');
        $review = new InboxCardWait($watch, 'document-in-review', InboxCardWaitType::Document, 'marker.document_in_review', documentId: Uuid::v7(), versionNumber: 1, startedAt: new \DateTimeImmutable('2026-10-13 09:00:00'));
        $watch->waits->add($review);
        $this->em()->persist($review);
        $this->em()->flush();
        $action = new InboxAction($item, InboxActionKind::ResumeRun, $this->project->owner, '', new \DateTimeImmutable('2026-10-14 01:00:00'));
        $action->waitId = $answered->id;
        $status = new InboxActionStatus($action, InboxActionState::Resumed, 'inbox.action.status.resumed');

        self::assertFalse($status->answers($watch->waits));

        $answered->endedAt = new \DateTimeImmutable('2026-10-14 01:00:05');

        self::assertFalse($status->answers($watch->waits));
    }

    public function test_an_action_answers_only_while_it_runs_and_a_wait_is_open(): void
    {
        $item = $this->runItem(WorkerRunState::Blocked);
        $watch = $this->watchOf($item);
        $answered = $watch->waits->first() ?: throw new \LogicException('A run item has a wait.');
        $action = new InboxAction($item, InboxActionKind::RetryCard, $this->project->owner);
        $action->waitId = $answered->id;

        self::assertFalse(new InboxActionStatus($action, InboxActionState::Expired, 'inbox.action.status.expired')->answers($watch->waits));

        $answered->endedAt = new \DateTimeImmutable();
        self::assertFalse(new InboxActionStatus($action, InboxActionState::Retried, 'inbox.action.status.retried')->answers($watch->waits));
    }

    /** @return list<InboxActionStatus> */
    private function statusLines(InboxItem $item): array
    {
        return self::getContainer()->get(InboxActionStatuses::class)->forItems([$item])[(string) $item->id] ?? [];
    }

    private function watchOf(InboxItem $item): InboxCardWatch
    {
        return self::getContainer()->get(InboxCardWatchRepository::class)->findOneForItem($item) ?? throw new \LogicException('A run item has a watch.');
    }

    private function runItem(WorkerRunState $state): InboxItem
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
        $item = new InboxItem(project: $this->project, number: 1, kind: InboxItemKind::Wait, title: '#1 Ship it', blocking: true, origin: InboxAskOrigin::Loupe);
        $this->em()->persist($item);
        $watch = new InboxCardWatch($item, $this->card->id, $this->card->number);
        $this->em()->persist($watch);
        $watch->waits->add(new InboxCardWait($watch, 'run-needs-you', InboxCardWaitType::WorkerRun, 'marker.run_needs_you', runId: $run->id));
        $this->em()->flush();

        return $item;
    }
}
