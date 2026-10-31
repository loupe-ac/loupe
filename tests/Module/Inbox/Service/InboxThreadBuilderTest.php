<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxCloseCause;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Service\InboxItemCloser;
use App\Module\Inbox\Service\InboxThreadBuilder;
use App\Module\Inbox\View\InboxActionState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class InboxThreadBuilderTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private Project $project;
    private Card $card;
    private Bridge $bridge;
    private InboxThreadBuilder $builder;
    private int $number = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->project = $this->project($em, $this->owner($em, 'thread'), 'thread');
        $this->card = $this->card($em, $this->project, 3);
        $this->bridge = new Bridge($this->project->owner, Uuid::v4(), [], 'b4e39aa7', new \DateTimeImmutable());
        $this->bridge->capabilities = [Bridge::CAPABILITY_COMMANDS];
        $this->bridge->lastSeenAt = new \DateTimeImmutable('-1 day');
        $em->persist($this->bridge);
        $em->flush();
        $builder = self::getContainer()->get(InboxThreadBuilder::class);
        self::assertInstanceOf(InboxThreadBuilder::class, $builder);
        $this->builder = $builder;
    }

    public function test_an_open_question_holds_the_body_and_the_reply_card_and_nothing_after(): void
    {
        $item = $this->item(InboxItemKind::Question, ['JSON', 'CSV'], body: 'Which one?');

        $thread = $this->builder->build($item);

        self::assertSame('Which one?', $thread->body);
        self::assertNotNull($thread->replyCard);
        self::assertNull($thread->reply);
        self::assertNull($thread->closing);
        self::assertNull($thread->refusalKey);
        self::assertSame([], $thread->actions);
        self::assertSame([], $thread->waits);
    }

    public function test_a_blank_body_is_no_body(): void
    {
        self::assertNull($this->builder->build($this->item(InboxItemKind::Todo, body: "  \n"))->body);
    }

    public function test_an_answer_is_final_and_shows_as_a_reply(): void
    {
        $item = $this->item(InboxItemKind::Question, ['JSON', 'CSV']);
        $this->close($item, InboxItemState::Resolved, InboxCloser::Owner);
        $item->selectedOptions = [1];
        $item->note = 'Use CSV, please.';
        $this->em->flush();

        $thread = $this->builder->build($item);

        self::assertNull($thread->replyCard);
        self::assertNull($thread->closing);
        self::assertSame(InboxItemCloser::ERROR_FINAL, $thread->refusalKey);
        self::assertNotNull($thread->reply);
        self::assertSame('inbox.thread.you_verb.answer', $thread->reply->verbKey);
        self::assertSame($item->closedAt, $thread->reply->at);
        self::assertNull($thread->reply->outcome, 'The verb already says the owner answered.');
        self::assertSame(['CSV'], $thread->reply->options);
        self::assertSame('Use CSV, please.', $thread->reply->note);
        self::assertFalse($thread->reply->throughAgent);
    }

    public function test_a_response_that_an_agent_recorded_for_the_owner_says_so(): void
    {
        $item = $this->item(InboxItemKind::Question, ['JSON']);
        $this->close($item, InboxItemState::Resolved, InboxCloser::OwnerThroughAgent);

        $thread = $this->builder->build($item);

        self::assertTrue($thread->reply?->throughAgent);
        self::assertNull($thread->closing);
    }

    public function test_a_dismissed_todo_shows_a_dismissal_with_its_note(): void
    {
        $item = $this->item(InboxItemKind::Todo);
        $this->close($item, InboxItemState::Declined, InboxCloser::Owner);
        $item->note = 'Not mine.';
        $this->em->flush();

        $reply = $this->builder->build($item)->reply;

        self::assertSame('inbox.thread.you_verb.dismiss', $reply?->verbKey);
        self::assertNull($reply->outcome, 'The verb already says the owner dismissed it.');
        self::assertSame('Not mine.', $reply->note);
        self::assertSame([], $reply->options);
    }

    public function test_a_completed_todo_shows_a_completion(): void
    {
        $item = $this->item(InboxItemKind::Todo);
        $this->close($item, InboxItemState::Resolved, InboxCloser::Owner);

        self::assertSame('inbox.thread.you_verb.complete', $this->builder->build($item)->reply?->verbKey);
    }

    #[DataProvider('closers')]
    public function test_a_close_by_the_agent_loupe_or_a_rule_is_a_closing_message(InboxCloser $closer, InboxItemState $state, ?InboxCloseCause $cause, string $outcomeKey): void
    {
        $item = $this->item(InboxItemKind::Question, ['JSON']);
        $this->close($item, $state, $closer);
        $item->closeCause = $cause;
        $item->note = 'Reason given.';
        $this->em->flush();

        $thread = $this->builder->build($item);

        self::assertNull($thread->reply);
        self::assertNull($thread->replyCard);
        self::assertNotNull($thread->closing);
        self::assertSame($closer, $thread->closing->closer);
        self::assertSame($outcomeKey, $thread->closing->outcome?->key);
        self::assertSame($item->closedAt, $thread->closing->at);
        self::assertSame('Reason given.', $thread->closing->note);
    }

    /** @return iterable<string, array{InboxCloser, InboxItemState, ?InboxCloseCause, string}> */
    public static function closers(): iterable
    {
        yield 'agent' => [InboxCloser::Agent, InboxItemState::Cancelled, InboxCloseCause::Withdrawn, 'inbox.outcome.cancelled'];
        yield 'loupe' => [InboxCloser::Loupe, InboxItemState::Cancelled, InboxCloseCause::CardDeleted, 'inbox.outcome.cancelled'];
        yield 'rule' => [InboxCloser::Rule, InboxItemState::Resolved, null, 'inbox.outcome.resolved'];
    }

    public function test_a_notice_holds_its_text_and_no_reply_card(): void
    {
        $item = $this->item(InboxItemKind::Notice, body: 'The bridge is back.', origin: InboxAskOrigin::Loupe);

        $thread = $this->builder->build($item);

        self::assertSame('The bridge is back.', $thread->body);
        self::assertNull($thread->replyCard);
    }

    public function test_a_workflow_question_shows_no_dismiss(): void
    {
        $thread = $this->builder->build($this->item(InboxItemKind::Workflow, ['Yes', 'No'], origin: InboxAskOrigin::Loupe));

        self::assertNotNull($thread->replyCard);
        self::assertNull($thread->replyCard->dismiss);
    }

    public function test_a_wait_holds_its_waits_and_no_body(): void
    {
        [$item, $wait] = $this->waitItem();

        $thread = $this->builder->build($item);

        self::assertNull($thread->body);
        self::assertSame($item, $thread->watch?->item);
        self::assertSame([$wait], $thread->waits);
        self::assertNotNull($thread->replyCard);
    }

    public function test_a_queued_action_hides_the_reply_card_and_shows_the_missing_bridge(): void
    {
        [$item, $wait] = $this->waitItem();
        $this->resumeAction($item, $wait);

        $thread = $this->builder->build($item);

        self::assertNull($thread->replyCard);
        self::assertCount(1, $thread->actions);
        self::assertSame(InboxActionState::Queued, $thread->actions[0]->state);
        self::assertTrue($thread->actions[0]->cancellable);
        self::assertTrue($thread->actions[0]->offline);
    }

    public function test_an_expired_action_brings_the_reply_card_back(): void
    {
        [$item, $wait] = $this->waitItem();
        $command = $this->resumeAction($item, $wait);
        $command->settle(BridgeCommandState::Expired, null, new \DateTimeImmutable());
        $this->em->flush();

        $thread = $this->builder->build($item);

        self::assertSame(InboxActionState::Expired, $thread->actions[0]->state);
        self::assertNotNull($thread->replyCard);
    }

    public function test_a_cancelled_action_brings_the_reply_card_back(): void
    {
        [$item, $wait] = $this->waitItem();
        $command = $this->resumeAction($item, $wait);
        $command->settle(BridgeCommandState::Cancelled, null, new \DateTimeImmutable());
        $this->em->flush();

        $thread = $this->builder->build($item);

        self::assertSame(InboxActionState::Cancelled, $thread->actions[0]->state);
        self::assertNotNull($thread->replyCard);
    }

    /** @param list<string> $options */
    private function item(InboxItemKind $kind, array $options = [], ?string $body = null, InboxAskOrigin $origin = InboxAskOrigin::Agent): InboxItem
    {
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: $kind, title: 'Item '.$this->number, blocking: false, origin: $origin, body: $body, options: $options);
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    private function close(InboxItem $item, InboxItemState $state, InboxCloser $closer): void
    {
        $item->state = $state;
        $item->closedBy = $closer;
        $item->closedAt = new \DateTimeImmutable('2026-10-20 09:30:00');
        $this->em->flush();
    }

    /** @return array{InboxItem, InboxCardWait} */
    private function waitItem(): array
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
        $wait = new InboxCardWait($watch, 'run-needs-you', InboxCardWaitType::WorkerRun, 'marker.run_needs_you', runId: $run->id);
        $watch->waits->add($wait);
        $this->em->flush();

        return [$item, $wait];
    }

    private function resumeAction(InboxItem $item, InboxCardWait $wait): BridgeCommand
    {
        $run = $this->em->find(WorkerRun::class, $wait->runId) ?? throw new \LogicException('The run is stored.');
        $command = new BridgeCommand(
            owner: $this->project->owner,
            bridgeId: $this->bridge->id,
            project: $this->project,
            workerRun: $run,
            kind: BridgeCommandKind::ResumeRun,
            requestedBy: null,
            requestedAt: new \DateTimeImmutable(),
            expiresAt: new \DateTimeImmutable('+15 minutes'),
        );
        $this->em->persist($command);
        $this->em->flush();
        $action = new InboxAction($item, InboxActionKind::ResumeRun, $this->project->owner);
        $action->bridgeCommandId = $command->id;
        $action->waitId = $wait->id;
        $this->em->persist($action);
        $this->em->flush();

        return $command;
    }
}
