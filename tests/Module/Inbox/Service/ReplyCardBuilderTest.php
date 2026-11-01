<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Inbox\Service\InboxItemCloser;
use App\Module\Inbox\Service\ReplyCardBuilder;
use App\Module\Inbox\View\ReplyField;
use App\Module\Inbox\View\ThreadAction;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ReplyCardBuilderTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private Project $project;
    private Card $card;
    private Bridge $bridge;
    private ReplyCardBuilder $builder;
    private int $number = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->project = $this->project($em, $this->owner($em, 'reply-card'), 'reply-card');
        $this->card = $this->card($em, $this->project, 3);
        $this->bridge = $bridge = new Bridge($this->project->owner, Uuid::v4(), [], 'b4e39aa7', new \DateTimeImmutable());
        $bridge->capabilities = [Bridge::CAPABILITY_COMMANDS];
        $em->persist($bridge);
        $em->flush();
        $builder = self::getContainer()->get(ReplyCardBuilder::class);
        self::assertInstanceOf(ReplyCardBuilder::class, $builder);
        $this->builder = $builder;
    }

    public function test_a_question_takes_options_and_a_note_and_says_the_answer_is_final(): void
    {
        $card = $this->builder->build($this->agentItem(InboxItemKind::Question, ['JSON', 'CSV']));

        self::assertNotNull($card);
        self::assertSame([ReplyField::Options, ReplyField::Note], $card->fields);
        self::assertSame('inbox.answer.final_hint', $card->hintKey);
        self::assertSame(InboxActionKind::Answer, $card->main?->action->kind);
        self::assertSame(InboxActionKind::Dismiss, $card->dismiss?->action->kind);
        self::assertSame([], $card->menu);
    }

    public function test_a_written_question_takes_a_note_alone(): void
    {
        $card = $this->builder->build($this->agentItem(InboxItemKind::Question, []));

        self::assertSame([ReplyField::Note], $card?->fields);
    }

    public function test_a_workflow_question_takes_no_note_and_has_no_dismiss(): void
    {
        $card = $this->builder->build($this->agentItem(InboxItemKind::Workflow, ['Yes', 'No']));

        self::assertNotNull($card);
        self::assertSame([ReplyField::Options], $card->fields);
        self::assertFalse($card->takes(ReplyField::Note));
        self::assertSame('inbox.answer.final_hint', $card->hintKey);
        self::assertSame(InboxActionKind::Answer, $card->main?->action->kind);
        self::assertNull($card->dismiss);
    }

    public function test_a_todo_takes_a_note_and_marks_complete_with_no_final_hint(): void
    {
        $card = $this->builder->build($this->agentItem(InboxItemKind::Todo));

        self::assertNotNull($card);
        self::assertSame([ReplyField::Note], $card->fields);
        self::assertNull($card->hintKey);
        self::assertSame(InboxActionKind::Complete, $card->main?->action->kind);
        self::assertSame('inbox.action.complete.label', $card->main->action->labelKey);
        self::assertSame(InboxActionKind::Dismiss, $card->dismiss?->action->kind);
    }

    public function test_dismiss_follows_the_flag_of_the_item(): void
    {
        $todo = $this->agentItem(InboxItemKind::Todo);
        $todo->dismissible = false;

        $card = $this->builder->build($todo);

        self::assertNotNull($card);
        self::assertNull($card->dismiss);
        self::assertSame(InboxActionKind::Complete, $card->main?->action->kind);
    }

    public function test_a_document_review_takes_a_verdict_and_a_note(): void
    {
        $item = $this->agentItem(InboxItemKind::Review);
        $review = new InboxReview($item, $this->document($this->em, $this->project));
        $this->em->persist($review);
        $this->em->flush();

        $card = $this->builder->build($item);

        self::assertNotNull($card);
        self::assertSame([ReplyField::Verdict, ReplyField::Note], $card->fields);
        self::assertSame($review, $card->review);
        self::assertFalse($card->targetGone);
        self::assertSame(InboxActionKind::SubmitReview, $card->main?->action->kind);
        self::assertSame(InboxActionKind::Dismiss, $card->dismiss?->action->kind);
    }

    public function test_a_review_whose_target_is_gone_offers_dismiss_whatever_the_flag_says(): void
    {
        $item = $this->agentItem(InboxItemKind::Review);
        $item->dismissible = false;
        $review = new InboxReview($item, $this->document($this->em, $this->project));
        $this->em->persist($review);
        $this->em->flush();
        $review->document = null;
        $this->em->flush();

        $card = $this->builder->build($item);

        self::assertNotNull($card);
        self::assertTrue($card->targetGone);
        self::assertSame([], $card->fields);
        self::assertNull($card->main);
        self::assertSame(InboxActionKind::Dismiss, $card->dismiss?->action->kind);
    }

    public function test_a_review_with_a_target_and_no_dismiss_flag_shows_no_dismiss(): void
    {
        $item = $this->agentItem(InboxItemKind::Review);
        $item->dismissible = false;
        $this->em->persist(new InboxReview($item, $this->document($this->em, $this->project)));
        $this->em->flush();

        $card = $this->builder->build($item);

        self::assertSame(InboxActionKind::SubmitReview, $card?->main?->action->kind);
        self::assertNull($card->dismiss);
    }

    public function test_a_notice_takes_no_response_and_has_no_card(): void
    {
        self::assertNull($this->builder->build($this->agentItem(InboxItemKind::Notice, [], InboxAskOrigin::Loupe)));
    }

    public function test_a_wait_takes_a_note_and_offers_the_action_of_its_wait(): void
    {
        $card = $this->builder->build($this->waitItem());

        self::assertNotNull($card);
        self::assertSame([ReplyField::Note], $card->fields);
        self::assertSame('inbox.thread.note_hint', $card->hintKey);
        self::assertSame(InboxActionKind::ResumeRun, $card->main?->action->kind);
        self::assertContainsOnlyInstancesOf(ThreadAction::class, $card->menu);
        self::assertSame(InboxActionKind::Dismiss, $card->dismiss?->action->kind);
    }

    public function test_a_closed_item_has_no_card_and_a_late_response_gets_the_refusal(): void
    {
        $item = $this->agentItem(InboxItemKind::Question, ['JSON']);

        self::assertNull($this->builder->refusalFor($item));

        $item->state = InboxItemState::Resolved;
        $item->closedBy = InboxCloser::Owner;
        $item->closedAt = new \DateTimeImmutable();
        $this->em->flush();

        self::assertNull($this->builder->build($item));
        self::assertSame(InboxItemCloser::ERROR_FINAL, $this->builder->refusalFor($item));
    }

    /** @param list<string> $options */
    private function agentItem(InboxItemKind $kind, array $options = [], InboxAskOrigin $origin = InboxAskOrigin::Agent): InboxItem
    {
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: $kind, title: 'Item '.$this->number, blocking: false, origin: $origin, options: $options);
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    private function waitItem(): InboxItem
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
        );
        $this->em->persist($run);
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Wait, title: '#3 Ship it', blocking: true, origin: InboxAskOrigin::Loupe);
        $this->em->persist($item);
        $watch = new InboxCardWatch($item, $this->card->id ?? throw new \LogicException('Stored.'), $this->card->number);
        $this->em->persist($watch);
        $watch->waits->add(new InboxCardWait($watch, 'run-needs-you', InboxCardWaitType::WorkerRun, 'marker.run_needs_you', runId: $run->id));
        $this->em->flush();

        return $item;
    }
}
