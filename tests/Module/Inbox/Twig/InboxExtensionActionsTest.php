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
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitReason;
use App\Module\Inbox\Entity\InboxCardWaitTrigger;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Twig\InboxExtension;
use App\Module\Inbox\View\InboxActionState;
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
        self::assertSame([InboxActionKind::Open], array_map(static fn ($row) => $row->action->kind, $actions->menu));
        self::assertSame('/projects/'.$this->project->id.'/board/cards/'.$this->card->id, $actions->menu[0]->href);
        self::assertTrue($actions->menu[0]->card);
        self::assertSame(InboxActionKind::Dismiss, $actions->dismiss?->action->kind);
    }

    public function test_the_card_page_gets_no_link_back_to_itself(): void
    {
        $item = $this->runItem(WorkerRunState::Blocked);

        $actions = $this->extension->actions($item, (string) $this->card->id);

        self::assertSame([], $actions->menu);
        self::assertSame(InboxActionKind::ResumeRun, $actions->main?->action->kind);
    }

    public function test_an_item_with_no_resume_defaults_to_open_as_a_link(): void
    {
        $item = $this->runItem(WorkerRunState::Running);

        $actions = $this->extension->actions($item);

        self::assertSame(InboxActionKind::Open, $actions->main?->action->kind);
        self::assertTrue($actions->main->isLink());
        self::assertSame([], $actions->menu);
        self::assertSame(InboxActionKind::Dismiss, $actions->dismiss?->action->kind);
    }

    public function test_an_item_with_only_dismiss_left_has_no_main_part(): void
    {
        $item = $this->runItem(WorkerRunState::Running);

        $actions = $this->extension->actions($item, (string) $this->card->id);

        self::assertNull($actions->main);
        self::assertSame([], $actions->menu);
        self::assertSame(InboxActionKind::Dismiss, $actions->dismiss?->action->kind);
        self::assertFalse($actions->isEmpty());
    }

    public function test_the_statuses_are_the_lines_of_the_item_oldest_first(): void
    {
        $item = $this->runItem(WorkerRunState::Blocked);

        $retried = new InboxAction($item, InboxActionKind::RetryCard, $this->project->owner, 'Again');
        $this->em()->persist($retried);
        $this->em()->flush();

        $lines = $this->extension->actionStatusLines($item);

        self::assertCount(1, $lines);
        self::assertSame(InboxActionState::Retried, $lines[0]->state);
        self::assertSame($retried, $lines[0]->action);
        self::assertTrue($lines[0]->state->inProgress());
    }

    public function test_an_item_with_no_action_has_no_status_line(): void
    {
        $item = $this->runItem(WorkerRunState::Blocked);

        self::assertSame([], $this->extension->actionStatusLines($item));
    }

    public function test_only_the_states_that_still_answer_the_wait_are_in_progress(): void
    {
        $running = array_filter(InboxActionState::cases(), static fn (InboxActionState $state): bool => $state->inProgress());

        self::assertSame(
            [InboxActionState::Queued, InboxActionState::Resumed, InboxActionState::Retried, InboxActionState::Running],
            array_values($running),
        );
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
        $item = new InboxItem(project: $this->project, number: 1, kind: InboxItemKind::Wait, title: '#1 Ship it', blocking: true);
        $this->em()->persist($item);
        $watch = new InboxCardWatch($item, $this->card->id, $this->card->number);
        $this->em()->persist($watch);
        $watch->waits->add(new InboxCardWait($watch, InboxCardWaitTrigger::RunBlocked, InboxCardWaitType::WorkerRun, InboxCardWaitReason::Blocked, runId: $run->id));
        $this->em()->flush();

        return $item;
    }
}
