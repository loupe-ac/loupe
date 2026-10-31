<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Engine;

use App\Module\Account\Repository\UserRepository;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Entity\DocumentAgentReview;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Workflow\DocumentsFactProvider;
use App\Module\Board\Workflow\DocumentsFacts;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\InteractiveRuns;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\PublishDocumentCommand;
use App\Module\Review\Command\PublishDocumentHandler;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Command\UndoVerdictCommand;
use App\Module\Review\Command\UndoVerdictHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Review;
use App\Module\Review\Repository\ReviewRepository;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Engine\Engine;
use App\Module\Workflow\Entity\WorkflowBinding;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Tests\Module\Workflow\Action\ActionScenario;
use App\Tests\Support\AgentCredential;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** The undo rules of the shipped Lifecycle template, with the real engine, the real providers and the real handlers. */
final class DesignUndoFlowTest extends KernelTestCase
{
    use ActionScenario;

    private const string CLEAN = "# Design\n\nNothing to decide here.";

    private ?Project $project = null;

    private ?WorkflowBinding $bound = null;

    public function test_an_undone_product_design_stops_the_worker_asks_for_changes_on_the_tech_design_and_moves_the_card_back(): void
    {
        $card = $this->card('implementation');
        $product = $this->approved($card, 'product-design');
        $tech = $this->approved($card, 'tech-design');
        $this->evaluate($card);
        $request = $this->liveRequest($card, 'implement');
        $run = $this->worker($card, 'implement');
        $workflow = $this->service(UserRepository::class)->workflow();

        $this->undo($product);
        $this->evaluate($card, '+1 minute');

        self::assertSame('product-design', $this->fresh($card)->column->slug);
        self::assertSame(WorkRequestState::Cancelled, $this->service(WorkRequestRepository::class)->find($request->id)?->state);
        $commands = $this->service(BridgeCommandRepository::class)->findAll();
        self::assertCount(1, $commands);
        self::assertSame([BridgeCommandKind::StopRun, (string) $run->id], [$commands[0]->kind, (string) $commands[0]->workerRun?->id]);
        self::assertSame(DocumentStatus::ChangesRequested, $tech->status);
        $reviews = $this->reviews($tech);
        self::assertSame(['approved', 'changes-requested'], array_map(static fn (Review $review): string => $review->verdict->value, $reviews));
        self::assertSame('product-design-undo', $reviews[1]->workflowRule);
        self::assertSame($workflow->id?->toRfc4122(), $reviews[1]->reviewer->id?->toRfc4122());
        self::assertStringContainsString('approval of the product design was undone', (string) $reviews[1]->note);
        self::assertSame(DocumentStatus::InReview, $product->status);

        $kinds = array_map(static fn (CardEvent $event): string => $event->kind->value, $this->history($card));
        self::assertContains('stopped', $kinds);
        $stopped = $this->events($card, CardEventKind::Stopped);
        self::assertCount(1, $stopped);
        self::assertSame(['product-design-undo', 'implement', (string) $run->id], [$stopped[0]->detail['rule'], $stopped[0]->detail['workKind'], $stopped[0]->detail['runId']]);
        $moves = $this->events($card, CardEventKind::Moved);
        self::assertSame(['type' => 'workflow-rule', 'rule' => 'product-design-undo'], $this->movedCause($moves[0]), 'The newest move names the rule.');
        $verdicts = array_values(array_filter($this->events($card, CardEventKind::DocumentVerdict), static fn (CardEvent $event): bool => 'changes-requested' === $event->detail['verdict']));
        self::assertCount(1, $verdicts);
        self::assertSame('product-design-undo', $verdicts[0]->detail['rule']);

        $this->evaluate($card, '+2 minutes');
        $this->evaluate($card, '+1 day');
        self::assertSame('product-design', $this->fresh($card)->column->slug, 'The undone design waits for a person.');
        self::assertCount(1, $this->service(BridgeCommandRepository::class)->findAll());
        self::assertCount(1, $this->events($card, CardEventKind::Stopped));
    }

    public function test_the_move_back_closes_an_interactive_session_that_stop_work_left_alone(): void
    {
        $card = $this->card('implementation');
        $product = $this->approved($card, 'product-design');
        $this->approved($card, 'tech-design');
        $this->evaluate($card);
        $session = $this->service(InteractiveRuns::class)->open($card->project, $card->id ?? throw new \LogicException('A flushed card has an id.'), $card->number, Uuid::v4(), 'implement');
        $worker = $this->worker($card, 'implement');
        self::assertSame(WorkerRunState::Running, $session->state);

        $this->undo($product);
        $this->evaluate($card, '+1 minute');

        self::assertSame('product-design', $this->fresh($card)->column->slug);
        $commands = $this->service(BridgeCommandRepository::class)->findAll();
        self::assertCount(1, $commands, 'Stop-work asks the bridge to stop the background run only.');
        self::assertSame((string) $worker->id, (string) $commands[0]->workerRun?->id);
        $stopped = $this->events($card, CardEventKind::Stopped);
        self::assertSame([(string) $worker->id], array_map(static fn (CardEvent $event): mixed => $event->detail['runId'], $stopped));
        $this->em()->clear();
        $closed = $this->em()->find(WorkerRun::class, $session->id);
        self::assertSame(WorkerRunState::Closed, $closed?->state, 'The move closes the row of the session.');
        self::assertNotNull($closed->endedAt);
        self::assertSame(WorkerRunState::Running, $this->em()->find(WorkerRun::class, $worker->id)?->state, 'The background run waits for the bridge to stop it.');
    }

    public function test_an_undone_design_waits_in_review_even_when_its_agent_review_passed(): void
    {
        $card = $this->card('product-design');
        $product = $this->draft($card, 'product-design', AgentReviewConclusion::Success);
        $this->evaluate($card);
        self::assertSame(DocumentStatus::Approved, $product->status, 'The auto-approve rule approved the clean design.');
        $this->evaluate($card, '+1 minute');
        self::assertSame('tech-design', $this->fresh($card)->column->slug);

        $this->undo($product);
        $this->evaluate($card, '+2 minutes');
        self::assertSame('product-design', $this->fresh($card)->column->slug);
        self::assertSame(DocumentStatus::InReview, $product->status);

        $this->evaluate($card, '+3 minutes');
        $this->evaluate($card, '+1 day');

        self::assertSame(DocumentStatus::InReview, $product->status);
        self::assertSame(['approved', 'withdrawn'], array_map(static fn (Review $review): string => $review->verdict->value, $this->reviews($product)));
        self::assertSame('product-design', $this->fresh($card)->column->slug);
        $facts = $this->service(DocumentsFactProvider::class)->build($card->snapshot());
        self::assertInstanceOf(DocumentsFacts::class, $facts);
        self::assertTrue($facts->documents[0]->rejectedBefore, 'The withdrawal counts as a rejection for the auto-approve rule.');
    }

    public function test_a_card_in_a_terminal_column_keeps_its_place_and_only_the_document_resets(): void
    {
        $card = $this->card('done');
        $product = $this->approved($card, 'product-design');
        $tech = $this->approved($card, 'tech-design');
        $this->evaluate($card);

        $this->undo($product);
        $this->evaluate($card, '+1 minute');
        $this->evaluate($card, '+1 day');

        self::assertSame('done', $this->fresh($card)->column->slug);
        self::assertSame(DocumentStatus::InReview, $product->status);
        self::assertSame(DocumentStatus::Approved, $tech->status, 'The tech design keeps its approval.');
        self::assertSame([], $this->events($card, CardEventKind::Stopped));
        self::assertSame([], $this->service(BridgeCommandRepository::class)->findAll());
        self::assertFalse($this->ruleTruth($card, 'product-design-undo'));
    }

    public function test_an_undone_tech_design_moves_the_card_back_and_leaves_the_product_design(): void
    {
        $card = $this->card('implementation');
        $product = $this->approved($card, 'product-design');
        $tech = $this->approved($card, 'tech-design');
        $this->evaluate($card);
        $run = $this->worker($card, 'implement');

        $this->undo($tech);
        $this->evaluate($card, '+1 minute');

        self::assertSame('tech-design', $this->fresh($card)->column->slug);
        self::assertSame(DocumentStatus::Approved, $product->status);
        self::assertSame(DocumentStatus::InReview, $tech->status);
        self::assertSame(['approved', 'withdrawn'], array_map(static fn (Review $review): string => $review->verdict->value, $this->reviews($tech)));
        self::assertCount(1, $this->service(BridgeCommandRepository::class)->findAll());
        self::assertSame([(string) $run->id], array_map(static fn (CardEvent $event): string => $event->detail['runId'], $this->events($card, CardEventKind::Stopped)));
        $moves = $this->events($card, CardEventKind::Moved);
        self::assertSame(['type' => 'workflow-rule', 'rule' => 'tech-design-undo'], $this->movedCause($moves[0]));
    }

    #[DataProvider('approvers')]
    public function test_an_undo_moves_the_card_the_same_whoever_gave_the_approval(bool $byRule): void
    {
        $card = $this->card('implementation');
        $tech = $this->approved($card, 'tech-design', $byRule);

        $this->undo($tech);
        $this->evaluate($card);

        self::assertSame('tech-design', $this->fresh($card)->column->slug);
    }

    /** @return iterable<string, array{bool}> */
    public static function approvers(): iterable
    {
        yield 'the owner approved' => [false];
        yield 'the workflow approved' => [true];
    }

    public function test_a_change_request_on_the_tech_design_starts_the_revise_work_when_the_card_comes_to_tech_design(): void
    {
        $card = $this->card('implementation');
        $product = $this->approved($card, 'product-design');
        $this->approved($card, 'tech-design');
        $this->evaluate($card);

        $this->undo($product);
        $this->evaluate($card, '+1 minute');
        self::assertSame('product-design', $this->fresh($card)->column->slug);
        $this->approveAgain($product);
        $this->evaluate($card, '+2 minutes');
        self::assertSame('tech-design', $this->fresh($card)->column->slug, 'The re-approved product design sends the card on.');
        $this->evaluate($card, '+3 minutes');

        self::assertSame(['tech-design-revise'], array_map(static fn (WorkRequest $request): string => $request->kind, $this->liveRequests($card)));
    }

    public function test_a_card_that_comes_back_to_implementation_with_an_open_pull_request_starts_no_implement_work(): void
    {
        $card = $this->card('implementation');
        $product = $this->approved($card, 'product-design');
        $tech = $this->approved($card, 'tech-design');
        $this->pullRequest($card);
        $this->evaluate($card);
        self::assertNotContains('implement', $this->liveKinds($card));

        $this->undo($product);
        $this->evaluate($card, '+1 minute');
        $this->approveAgain($product);
        $this->evaluate($card, '+2 minutes');
        $this->evaluate($card, '+3 minutes');
        $this->approveAgain($tech);
        $this->evaluate($card, '+4 minutes');
        $this->evaluate($card, '+5 minutes');

        self::assertSame('in-progress', $this->fresh($card)->column->slug, 'The card is back in implementation.');
        self::assertNotContains('implement', $this->liveKinds($card));
        $implementRequests = array_filter($this->service(WorkRequestRepository::class)->findAll(), static fn (WorkRequest $request): bool => 'implement' === $request->kind);
        self::assertSame([], array_values($implementRequests));
    }

    public function test_an_epic_with_open_children_moves_back_like_any_card(): void
    {
        $card = $this->card('implementation', 'epic');
        $child = $this->card('implementation');
        $child->parent = $card;
        $this->em()->flush();
        $product = $this->approved($card, 'product-design');
        $this->approved($card, 'tech-design');

        $this->undo($product);
        $this->evaluate($card);

        self::assertSame('product-design', $this->fresh($card)->column->slug, 'The app moves the card as the system, and only a move to a terminal column counts the children.');
    }

    public function test_a_past_undo_with_a_stored_true_state_moves_no_card_and_one_without_it_does(): void
    {
        $stored = $this->card('implementation');
        $product = $this->approved($stored, 'product-design');
        $this->undo($product);
        $this->state($stored, 'product-design-undo', true);
        $this->state($stored, 'tech-design-undo', true);

        $this->evaluate($stored);

        self::assertSame('in-progress', $this->fresh($stored)->column->slug);
        self::assertTrue($this->ruleTruth($stored, 'product-design-undo'));

        $fresh = $this->card('implementation');
        $other = $this->approved($fresh, 'product-design');
        $this->undo($other);

        $this->evaluate($fresh);

        self::assertSame('product-design', $this->fresh($fresh)->column->slug);
    }

    private function card(string $column, string $type = 'feature'): Card
    {
        if (null === $this->project) {
            self::bootKernel();
        }
        $project = $this->project ??= $this->workflowProject('design-undo');
        $this->bound ??= $this->bindLifecycle($project);
        if ('implementation' === $column) {
            $column = 'in-progress';
        }
        $card = new Card($project, $this->column($project, $column), 'Card', '', ++$this->cardNumber, $type);
        $this->em()->persist($card);
        $this->em()->flush();
        $this->service(CardEventRepository::class)->record($card, CardEventKind::Created, Actor::System, null, [], new \DateTimeImmutable('-1 hour'));
        $this->em()->flush();

        return $card;
    }

    /** A document with a version in review, linked to the card, approved by the owner or by the workflow. */
    private function approved(Card $card, string $tag, bool $byRule = false): Document
    {
        $document = $this->draft($card, $tag);
        $this->service(PublishDocumentHandler::class)(new PublishDocumentCommand($document));
        $workflow = $this->service(UserRepository::class)->workflow();
        $this->service(SubmitReviewHandler::class)(new SubmitReviewCommand(
            $byRule ? $workflow : $card->project->owner,
            $document,
            'approved',
            1,
            workflowRule: $byRule ? $tag.'-auto-approve' : null,
        ));

        return $document;
    }

    private function draft(Card $card, string $tag, ?AgentReviewConclusion $review = null): Document
    {
        $document = $this->service(CreateDocumentHandler::class)(new CreateDocumentCommand($card->project, ucfirst($tag), self::CLEAN, tagNames: [$tag], draft: true));
        $this->em()->persist(new CardDocument($card, $document));
        if (null !== $review) {
            $this->em()->persist(new DocumentAgentReview($card->project, $card, $document, 1, 'Summary.', $review, [], createdAt: new \DateTimeImmutable('-30 minutes')));
        }
        $this->em()->flush();

        return $document;
    }

    private function undo(Document $document): void
    {
        $newest = $this->reviews($document);
        $last = array_last($newest) ?? throw new \LogicException('The document has a review.');
        $this->service(UndoVerdictHandler::class)(new UndoVerdictCommand($document, $document->project->owner, $last->id?->toRfc4122() ?? ''));
    }

    /** A person approves the withdrawn document again. */
    private function approveAgain(Document $document): void
    {
        $newest = $this->reviews($document);
        $this->service(SubmitReviewHandler::class)(new SubmitReviewCommand(
            $document->project->owner,
            $document,
            'approved',
            1,
            expectedReviewId: array_last($newest)?->id?->toRfc4122(),
        ));
    }

    private function worker(Card $card, string $kind): WorkerRun
    {
        $owner = $card->project->owner;
        $bridge = new Bridge(AgentCredential::managed($this->em(), $owner, $owner->id), Uuid::v4(), [], 'b4e39aa7', new \DateTimeImmutable());
        $bridge->capabilities = [Bridge::CAPABILITY_COMMANDS];
        $this->em()->persist($bridge);
        $run = new WorkerRun(
            project: $card->project,
            bridgeId: $bridge->id,
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('A flushed card has an id.'),
            cardNumber: $card->number,
            workKind: $kind,
            state: WorkerRunState::Running,
            startedAt: new \DateTimeImmutable('-4 minutes'),
            receivedAt: new \DateTimeImmutable(),
            kind: WorkerRunKind::Worker,
        );
        $this->em()->persist($run);
        $this->em()->flush();

        return $run;
    }

    private function state(Card $card, string $ruleId, bool $truth): void
    {
        $state = new WorkflowRuleState($card->id ?? throw new \LogicException('A flushed card has an id.'), $card->project, $ruleId);
        $state->truth = $truth;
        $this->em()->persist($state);
        $this->em()->flush();
    }

    private function evaluate(Card $card, string $at = 'now'): void
    {
        $this->service(Engine::class)->evaluate($card->id ?? throw new \LogicException('A flushed card has an id.'), new \DateTimeImmutable($at));
    }

    /** @return list<Review> */
    private function reviews(Document $document): array
    {
        $this->em()->flush();

        return $this->service(ReviewRepository::class)->findHistoryByDocument($document);
    }

    private function fresh(Card $card): Card
    {
        $this->em()->flush();

        return $card;
    }

    /** @return list<CardEvent> newest first */
    private function history(Card $card): array
    {
        $this->em()->flush();

        return $this->service(CardEventRepository::class)->findForCard($card);
    }

    /** @return list<CardEvent> newest first */
    private function events(Card $card, CardEventKind $kind): array
    {
        return array_values(array_filter($this->history($card), static fn (CardEvent $event): bool => $kind === $event->kind));
    }

    /** @return array<string, mixed>|null */
    private function movedCause(CardEvent $event): ?array
    {
        $cause = $event->detail['cause'] ?? null;

        return \is_array($cause) ? $cause : null;
    }

    /** @return list<WorkRequest> */
    private function liveRequests(Card $card): array
    {
        return $this->service(WorkRequestRepository::class)->findLiveForCard($card->id ?? throw new \LogicException('A flushed card has an id.'));
    }

    /** @return list<string> */
    private function liveKinds(Card $card): array
    {
        return array_map(static fn (WorkRequest $request): string => $request->kind, $this->liveRequests($card));
    }

    private function liveRequest(Card $card, string $kind): WorkRequest
    {
        foreach ($this->liveRequests($card) as $request) {
            if ($kind === $request->kind) {
                return $request;
            }
        }

        throw new \LogicException('The card has no live '.$kind.' request.');
    }

    private function ruleTruth(Card $card, string $ruleId): bool
    {
        $state = $this->service(WorkflowRuleStateRepository::class)->findForCard($card->id ?? throw new \LogicException('A flushed card has an id.'))[$ruleId] ?? null;
        self::assertNotNull($state, 'The engine evaluated the rule.');

        return $state->truth;
    }
}
