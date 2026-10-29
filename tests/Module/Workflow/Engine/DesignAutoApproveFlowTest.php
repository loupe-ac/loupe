<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Engine;

use App\Module\Account\Repository\UserRepository;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Entity\DocumentAgentReview;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Service\CardPullRequests;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Review\Command\AddCommentCommand;
use App\Module\Review\Command\AddCommentHandler;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\PublishDocumentCommand;
use App\Module\Review\Command\PublishDocumentHandler;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Command\SaveDecisionAnswerCommand;
use App\Module\Review\Command\SaveDecisionAnswerHandler;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Review;
use App\Module\Review\Repository\ReviewRepository;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Engine\Engine;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Tests\Module\Workflow\Action\ActionScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The auto-approve rules of the shipped Lifecycle template, with the real engine, the real providers and the real handlers. */
final class DesignAutoApproveFlowTest extends KernelTestCase
{
    use ActionScenario;

    private const string CLEAN = "# Design\n\nNothing to decide here.";
    private const string DECISION = "# Design\n\nThe quoted passage.\n\n<!-- decision: target -->\n\n- ( ) Staging\n- ( ) Production\n\n<!-- /decision -->";

    public function test_a_bug_design_with_nothing_open_and_a_passed_review_approves_itself_and_the_card_moves_on(): void
    {
        $card = $this->card('bug');
        $document = $this->draft($card, self::CLEAN, review: AgentReviewConclusion::Success);
        $inboxItems = $this->rowCount('inbox_items');

        $this->evaluate($card);

        $reviews = $this->reviews($document);
        self::assertCount(1, $reviews);
        self::assertSame('approved', $reviews[0]->verdict->value);
        self::assertSame('tech-design-auto-approve', $reviews[0]->workflowRule);
        self::assertSame($this->service(UserRepository::class)->workflow()->id?->toRfc4122(), $reviews[0]->reviewer->id?->toRfc4122());
        self::assertSame(DocumentStatus::Approved, $this->reload($document)->status);
        self::assertSame('in-progress', $this->fresh($card)->column->slug, 'tech-design-approved moves the card to implementation.');

        $verdicts = $this->service(CardEventRepository::class)->findBy(['card' => $card->id, 'kind' => CardEventKind::DocumentVerdict]);
        self::assertCount(1, $verdicts);
        self::assertSame(Actor::System, $verdicts[0]->actorKind);
        self::assertSame('approved', $verdicts[0]->detail['verdict']);
        self::assertSame('tech-design-auto-approve', $verdicts[0]->detail['rule']);
        self::assertSame([], $this->service(CardEventRepository::class)->findBy(['card' => $card->id, 'kind' => CardEventKind::VerdictFailed]));
        self::assertSame($inboxItems, $this->rowCount('inbox_items'), 'No document-in-review item opens, because the approval runs before the marker pass.');
    }

    public function test_the_pass_that_approves_and_moves_never_upgrades_to_a_for_update_lock_on_the_project(): void
    {
        $card = $this->card('bug');
        $this->draft($card, self::CLEAN, review: AgentReviewConclusion::Success);
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $holder->reset();

        $this->evaluate($card);

        $locks = [];
        foreach ($holder->getData() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                $sql = (string) $query['sql'];
                if (str_contains($sql, 'FROM projects') && 1 === preg_match('/\bFOR (UPDATE|NO KEY UPDATE)\b/', $sql)) {
                    $locks[] = $sql;
                }
            }
        }
        self::assertSame('in-progress', $this->fresh($card)->column->slug, 'The approval and the move ran in the pass.');
        self::assertNotEmpty($locks);
        self::assertSame([], array_values(array_filter($locks, static fn (string $sql): bool => 1 === preg_match('/\bFOR UPDATE\b/', $sql))), implode("\n", $locks));
    }

    public function test_a_new_card_that_links_a_merged_pull_request_gets_implement_work_and_stays_out_of_the_terminal_column(): void
    {
        $card = $this->card('bug');
        $this->draft($card, self::CLEAN, review: AgentReviewConclusion::Success);
        $reference = $this->pullRequest($card, PullRequestState::Merged);
        $reference->mergedAt = new \DateTimeImmutable('-2 hours');
        $this->em()->flush();
        self::assertCount(1, $this->service(CardPullRequests::class)->currentUrls($card));

        $this->evaluate($card);
        $this->evaluate($card, '+1 minute');
        $this->evaluate($card, '+2 minutes');

        $card = $this->fresh($card);
        self::assertSame('in-progress', $card->column->slug);
        self::assertFalse($card->column->terminal);
        $requests = $this->service(WorkRequestRepository::class)->findBy(['subjectId' => $card->id, 'kind' => 'implement']);
        self::assertCount(1, $requests);
        self::assertEquals(new WorkRequestContext(), $requests[0]->context);
    }

    public function test_a_product_design_approves_itself_whatever_the_card_type(): void
    {
        $card = $this->card('feature', 'product-design');
        $document = $this->draft($card, self::CLEAN, tag: 'product-design', review: AgentReviewConclusion::Success);

        $this->evaluate($card);

        self::assertSame(DocumentStatus::Approved, $this->reload($document)->status);
        self::assertSame('product-design-auto-approve', $this->reviews($document)[0]->workflowRule);
        self::assertSame('tech-design', $this->fresh($card)->column->slug);
    }

    public function test_a_feature_tech_design_approves_itself(): void
    {
        $card = $this->card('feature');
        $document = $this->draft($card, self::CLEAN, review: AgentReviewConclusion::Success);

        $this->evaluate($card);

        self::assertSame(DocumentStatus::Approved, $this->reload($document)->status);
        self::assertSame('tech-design-auto-approve', $this->reviews($document)[0]->workflowRule);
        self::assertSame('in-progress', $this->fresh($card)->column->slug);
    }

    #[DataProvider('openAtEntry')]
    public function test_an_open_decision_or_thread_at_entry_stops_the_approval_for_good(string $markdown, bool $thread): void
    {
        $card = $this->card('bug');
        $document = $this->draft($card, $markdown, review: AgentReviewConclusion::Success);
        if ($thread) {
            $this->service(AddCommentHandler::class)(new AddCommentCommand($document->owner, $document, 1, 'The quoted passage.', '', '', 'Look again.'));
        }

        $this->evaluate($card);

        self::assertSame(DocumentStatus::InReview, $this->reload($document)->status);
        self::assertSame([], $this->reviews($document));
        self::assertFalse($this->ruleTruth($card, 'tech-design-auto-approve'), 'The conditions of the rule hold it back, not the guard of the action.');
        $this->evaluate($card, '+10 minutes');
        $this->evaluate($card, '+1 day');
        self::assertSame(DocumentStatus::InReview, $this->reload($document)->status, 'The sweep approves nothing after the entry.');
        self::assertFalse($this->ruleTruth($card, 'tech-design-auto-approve'));
        self::assertSame('tech-design', $this->fresh($card)->column->slug);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function openAtEntry(): iterable
    {
        yield 'a decision with no answer' => [self::DECISION, false];
        yield 'a thread that is not resolved' => ["# Design\n\nThe quoted passage.", true];
    }

    public function test_an_answer_that_comes_after_the_entry_does_not_approve_the_document(): void
    {
        $card = $this->card('bug');
        $document = $this->draft($card, self::DECISION, review: AgentReviewConclusion::Success);
        $this->evaluate($card);
        self::assertSame(DocumentStatus::InReview, $this->reload($document)->status);

        $this->service(SaveDecisionAnswerHandler::class)(new SaveDecisionAnswerCommand($document, 'target', 1, [1], null, false, $card->project->owner));
        $this->evaluate($card, '+10 minutes');

        self::assertSame(DocumentStatus::InReview, $this->reload($document)->status);
        self::assertSame([], $this->reviews($document));
    }

    public function test_a_design_review_that_gave_up_on_a_failure_publishes_the_draft_and_does_not_approve_it(): void
    {
        $card = $this->card('bug');
        $document = $this->draft($card, self::CLEAN, review: AgentReviewConclusion::Failure, reviews: 6);

        $this->evaluate($card);

        self::assertSame(DocumentStatus::InReview, $this->reload($document)->status, 'The finished loop still publishes.');
        self::assertSame([], $this->reviews($document));
        self::assertSame('tech-design', $this->fresh($card)->column->slug);
    }

    public function test_a_design_with_no_agent_review_at_all_is_not_approved(): void
    {
        $card = $this->card('bug');
        $document = $this->draft($card, self::CLEAN);
        $this->publishNow($document);

        $this->evaluate($card);

        self::assertSame(DocumentStatus::InReview, $this->reload($document)->status);
        self::assertSame([], $this->reviews($document));
    }

    public function test_a_fix_after_a_change_request_waits_for_a_person(): void
    {
        $card = $this->card('bug');
        $document = $this->draft($card, self::CLEAN, review: AgentReviewConclusion::Success);
        $this->publishNow($document);
        $this->service(SubmitReviewHandler::class)(new SubmitReviewCommand($document->owner, $document, 'changes-requested', 1, 'Rework it.'));
        $this->service(ReviseDocumentHandler::class)(new ReviseDocumentCommand($document, "# Design\n\nReworked, nothing to decide.", 'Reworked.'));
        self::assertSame(DocumentStatus::InReview, $document->status);

        $this->evaluate($card);
        $this->evaluate($card, '+1 day');

        self::assertSame(DocumentStatus::InReview, $this->reload($document)->status);
        self::assertSame(['changes-requested'], array_map(static fn (Review $review): string => $review->verdict->value, $this->reviews($document)));
        self::assertSame('tech-design', $this->fresh($card)->column->slug);
    }

    public function test_a_revision_of_a_document_in_review_leaves_no_clean_entry(): void
    {
        $card = $this->card('bug');
        $document = $this->draft($card, self::CLEAN, review: AgentReviewConclusion::Success);
        $this->publishNow($document);
        $this->service(ReviseDocumentHandler::class)(new ReviseDocumentCommand($document, "# Design\n\nA late edit.", 'A late edit.'));

        $this->evaluate($card);

        self::assertSame(DocumentStatus::InReview, $this->reload($document)->status);
        self::assertSame([], $this->reviews($document));
    }

    public function test_a_revision_of_an_approved_design_gets_no_approval_from_a_review_of_the_old_version(): void
    {
        $card = $this->card('bug');
        $document = $this->draft($card, self::CLEAN, review: AgentReviewConclusion::Success);
        $this->evaluate($card);
        self::assertSame(DocumentStatus::Approved, $this->reload($document)->status);
        $card->column = $this->column($card->project, 'tech-design');
        $this->em()->flush();
        $this->arrive($card, CardEventKind::Moved, '-1 second');
        $this->service(ReviseDocumentHandler::class)(new ReviseDocumentCommand($document, "# Design\n\nRevised, nothing to decide.", 'Revised.'));
        self::assertSame(DocumentStatus::InReview, $document->status, 'The revision puts the approved document back in review.');

        $this->evaluate($card, '+1 day');

        self::assertSame(DocumentStatus::InReview, $this->reload($document)->status);
        self::assertCount(1, $this->reviews($document), 'Only the first approval exists.');
        self::assertFalse($this->ruleTruth($card, 'tech-design-auto-approve'));
    }

    public function test_a_design_created_in_review_is_never_published_and_gets_no_approval(): void
    {
        $card = $this->card('bug');
        $document = $this->service(CreateDocumentHandler::class)(new CreateDocumentCommand($card->project, 'Design', self::CLEAN, tagNames: ['tech-design']));
        $this->em()->persist(new CardDocument($card, $document));
        $this->em()->persist(new DocumentAgentReview($card->project, $card, $document, 1, 'Summary.', AgentReviewConclusion::Success, []));
        $this->em()->flush();
        self::assertSame(DocumentStatus::InReview, $document->status);

        $this->evaluate($card);

        self::assertSame(DocumentStatus::InReview, $this->reload($document)->status);
        self::assertSame([], $this->reviews($document));
        self::assertFalse($this->ruleTruth($card, 'tech-design-auto-approve'));
    }

    public function test_a_revision_that_puts_an_approved_design_back_in_review_is_no_publish_even_with_a_passed_review_of_it(): void
    {
        $card = $this->card('bug');
        $document = $this->draft($card, self::CLEAN, review: AgentReviewConclusion::Success);
        $this->evaluate($card);
        self::assertSame(DocumentStatus::Approved, $this->reload($document)->status);
        $card->column = $this->column($card->project, 'tech-design');
        $this->em()->flush();
        $this->arrive($card, CardEventKind::Moved, '-1 second');
        $this->service(ReviseDocumentHandler::class)(new ReviseDocumentCommand($document, "# Design\n\nRevised, nothing to decide.", 'Revised.'));
        $this->em()->persist(new DocumentAgentReview($card->project, $card, $document, 2, 'Summary.', AgentReviewConclusion::Success, []));
        $this->em()->flush();

        $this->evaluate($card, '+1 day');

        self::assertSame(DocumentStatus::InReview, $this->reload($document)->status);
        self::assertCount(1, $this->reviews($document), 'Only the approval of the published version exists.');
        self::assertFalse($this->ruleTruth($card, 'tech-design-auto-approve'));
    }

    /** @param list<array{CardEventKind, string}> $arrivals */
    #[DataProvider('lateArrivals')]
    public function test_a_card_that_arrived_in_the_column_after_the_entry_gets_no_approval(array $arrivals): void
    {
        $card = $this->card('bug', arrival: null);
        $document = $this->draft($card, self::CLEAN, review: AgentReviewConclusion::Success);
        $this->publishNow($document);
        foreach ($arrivals as [$kind, $at]) {
            $this->arrive($card, $kind, $at);
        }

        $this->evaluate($card);

        self::assertSame(DocumentStatus::InReview, $this->reload($document)->status);
        self::assertSame([], $this->reviews($document));
        self::assertSame('tech-design', $this->fresh($card)->column->slug);
    }

    /** @return iterable<string, array{list<array{CardEventKind, string}>}> */
    public static function lateArrivals(): iterable
    {
        yield 'the card moved in later' => [[[CardEventKind::Created, '-1 day'], [CardEventKind::Moved, '+1 hour']]];
        yield 'the card left the slot and came back' => [[[CardEventKind::Created, '-1 day'], [CardEventKind::Moved, '-1 hour'], [CardEventKind::Moved, '+1 hour'], [CardEventKind::Moved, '+2 hours']]];
        yield 'the card has no known arrival' => [[]];
    }

    public function test_a_document_that_was_in_review_before_the_deploy_has_no_entry_check_and_gets_no_approval(): void
    {
        $card = $this->card('bug');
        $document = $this->draft($card, self::CLEAN, review: AgentReviewConclusion::Success);
        $this->publishNow($document);
        $this->em()->getConnection()->executeStatement('UPDATE document_versions SET published_at = NULL, entry_unanswered_decisions = NULL, entry_open_threads = NULL WHERE document_id = ?', [(string) $document->id]);

        $this->evaluate($card);
        $this->evaluate($card, '+10 minutes');

        self::assertSame(DocumentStatus::InReview, $this->reload($document)->status);
        self::assertSame([], $this->reviews($document));
        self::assertSame('tech-design', $this->fresh($card)->column->slug);
    }

    private function card(string $type, string $column = 'tech-design', ?string $arrival = '-1 hour'): Card
    {
        self::bootKernel();
        $project = $this->workflowProject('auto-approve-flow');
        $this->bindLifecycle($project);
        $card = new Card($project, $this->column($project, $column), 'Card', '', 1, $type);
        $this->em()->persist($card);
        $this->em()->flush();
        if (null !== $arrival) {
            $this->arrive($card, CardEventKind::Created, $arrival);
        }

        return $card;
    }

    private function arrive(Card $card, CardEventKind $kind, string $at): void
    {
        $this->service(CardEventRepository::class)->record($card, $kind, Actor::System, null, [], new \DateTimeImmutable($at));
        $this->em()->flush();
    }

    /** A draft linked to the card, with agent reviews of the given conclusion on its first version. */
    private function draft(Card $card, string $markdown, string $tag = 'tech-design', ?AgentReviewConclusion $review = null, int $reviews = 1): Document
    {
        $document = $this->service(CreateDocumentHandler::class)(new CreateDocumentCommand($card->project, 'Design', $markdown, tagNames: [$tag], draft: true));
        $this->em()->persist(new CardDocument($card, $document));
        if (null !== $review) {
            for ($round = 0; $round < $reviews; ++$round) {
                $this->em()->persist(new DocumentAgentReview($card->project, $card, $document, 1, 'Summary.', $review, [], createdAt: new \DateTimeImmutable(\sprintf('-%d minutes', 30 - $round))));
            }
        }
        $this->em()->flush();

        return $document;
    }

    private function publishNow(Document $document): void
    {
        $this->service(PublishDocumentHandler::class)(new PublishDocumentCommand($document));
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

    private function reload(Document $document): Document
    {
        $this->em()->flush();

        return $document;
    }

    private function fresh(Card $card): Card
    {
        $this->em()->flush();

        return $card;
    }

    private function ruleTruth(Card $card, string $ruleId): bool
    {
        $state = $this->service(WorkflowRuleStateRepository::class)->findForCard($card->id ?? throw new \LogicException('A flushed card has an id.'))[$ruleId] ?? null;
        self::assertNotNull($state, 'The engine evaluated the rule.');

        return $state->truth;
    }

    private function rowCount(string $table): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }
}
