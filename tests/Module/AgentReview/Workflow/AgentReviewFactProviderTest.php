<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Workflow;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Service\AgentReviewNoticeBody;
use App\Module\AgentReview\Workflow\AgentReviewFactProvider;
use App\Module\AgentReview\Workflow\AgentReviewFacts;
use App\Module\AgentReview\Workflow\Condition\AgentReviewDue;
use App\Module\AgentReview\Workflow\Condition\AgentReviewFailed;
use App\Module\AgentReview\Workflow\Condition\AgentReviewOverridden;
use App\Module\AgentReview\Workflow\Condition\AgentReviewPassed;
use App\Module\AgentReview\Workflow\Condition\AgentReviewUncommented;
use App\Module\AgentReview\Workflow\Condition\AgentReviewUnposted;
use App\Module\AgentReview\Workflow\ReviewedHead;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Entity\PullRequestNotice;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Facts;
use App\Tests\Module\AgentReview\AgentReviewScenario;
use App\Tests\Module\Workflow\Fact\FactsMother;
use App\Tests\Support\AgentCredential;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AgentReviewFactProviderTest extends KernelTestCase
{
    use AgentReviewScenario;

    private EntityManagerInterface $em;

    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->project = $this->makeProject('agent-review-facts');
    }

    public function test_the_provider_is_on_and_names_its_source(): void
    {
        self::assertTrue($this->provider()->isOn());
        self::assertSame(AgentReviewFacts::class, $this->provider()->factsClass());
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        self::assertSame('Agent review', $translator->trans($this->provider()->source()));
    }

    public function test_a_card_with_no_pull_request_has_no_heads(): void
    {
        $card = $this->card($this->project);
        $this->em->flush();

        self::assertEquals(new AgentReviewFacts([], false, []), $this->build($card));
    }

    public function test_an_open_pull_request_head_without_a_review_has_no_conclusion(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);

        $facts = $this->build($card);

        self::assertEquals([new ReviewedHead((string) $pullRequest->id, str_repeat('a', 40), null)], $facts->heads);
    }

    public function test_the_head_carries_the_conclusion_of_its_newest_review(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $this->review($card, $pullRequest);

        self::assertSame(AgentReviewConclusion::Failure, $this->build($card)->heads[0]->conclusion);
    }

    public function test_a_review_of_an_older_head_does_not_count(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $this->review($card, $pullRequest);
        $pullRequest->headSha = str_repeat('f', 40);

        self::assertNull($this->build($card)->heads[0]->conclusion);
    }

    public function test_a_carried_success_passes_and_is_not_due(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $this->carry($card, $pullRequest, AgentReviewConclusion::Success, 'change-1');

        $facts = $this->conditionFacts($card, $pullRequest);

        self::assertFalse(new AgentReviewDue()->evaluate($facts, []));
        self::assertTrue(new AgentReviewPassed()->evaluate($facts, []));
        self::assertFalse(new AgentReviewFailed()->evaluate($facts, []));
        self::assertTrue(new AgentReviewUnposted()->evaluate($facts, []));
    }

    public function test_a_carried_failure_stays_a_failure(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $this->carry($card, $pullRequest, AgentReviewConclusion::Failure, 'change-1');

        $facts = $this->conditionFacts($card, $pullRequest);

        self::assertFalse(new AgentReviewDue()->evaluate($facts, []));
        self::assertTrue(new AgentReviewFailed()->evaluate($facts, []));
        self::assertFalse(new AgentReviewPassed()->evaluate($facts, []));
    }

    public function test_an_approval_of_the_head_after_a_failed_review_overrides_it(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $this->review($card, $pullRequest);
        $this->approve($pullRequest, '+1 hour');

        $facts = $this->conditionFacts($card, $pullRequest);

        self::assertSame('ubermuda', $this->build($card)->heads[0]->overriddenBy);
        self::assertTrue(new AgentReviewOverridden()->evaluate($facts, []));
        self::assertFalse(new AgentReviewFailed()->evaluate($facts, []));
        self::assertFalse(new AgentReviewDue()->evaluate($facts, []));
        self::assertFalse(new AgentReviewPassed()->evaluate($facts, []));
    }

    public function test_the_override_ends_with_the_approval_and_changes_the_fingerprint(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $this->review($card, $pullRequest);
        $this->approve($pullRequest, '+1 hour');
        $provider = $this->provider();
        $withApproval = $provider->fingerprint($this->build($card));

        $pullRequest->review = PullRequestReview::None;
        $pullRequest->latestApprovedAt = $pullRequest->latestApprovalId = $pullRequest->latestApproverLogin = null;
        $facts = $this->conditionFacts($card, $pullRequest);

        self::assertNull($this->build($card)->heads[0]->overriddenBy);
        self::assertNotSame($withApproval, $provider->fingerprint($this->build($card)));
        self::assertFalse(new AgentReviewOverridden()->evaluate($facts, []));
        self::assertTrue(new AgentReviewFailed()->evaluate($facts, []));
    }

    public function test_an_approval_that_came_before_the_review_does_not_override_it(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $this->review($card, $pullRequest);
        $this->approve($pullRequest, '-1 hour');

        self::assertNull($this->build($card)->heads[0]->overriddenBy);
    }

    private function approve(ForgePullRequest $pullRequest, string $at): void
    {
        $pullRequest->review = PullRequestReview::Approved;
        $pullRequest->coveredSha = $pullRequest->headSha;
        $pullRequest->latestApprovedAt = new \DateTimeImmutable($at);
        $pullRequest->latestApprovalId = 'PRR_latest';
        $pullRequest->latestApproverLogin = 'ubermuda';
    }

    public function test_a_hand_resolved_conflict_with_another_change_is_due(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $this->carry($card, $pullRequest, AgentReviewConclusion::Success, 'change-2');

        $facts = $this->conditionFacts($card, $pullRequest);

        self::assertTrue(new AgentReviewDue()->evaluate($facts, []));
        self::assertFalse(new AgentReviewPassed()->evaluate($facts, []));
        self::assertFalse(new AgentReviewUnposted()->evaluate($facts, []));
    }

    public function test_a_fix_round_for_the_review_that_ended_done_after_it_answers_it(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $this->review($card, $pullRequest);
        $this->fix($card, 7, '+1 hour');

        $facts = $this->conditionFacts($card, $pullRequest);

        self::assertTrue($this->build($card)->heads[0]->answered);
        self::assertTrue(new AgentReviewDue()->evaluate($facts, []));
        self::assertFalse(new AgentReviewFailed()->evaluate($facts, []));
    }

    public function test_a_done_request_with_the_fix_role_answers_the_review_whatever_its_kind(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $this->review($card, $pullRequest);
        $this->fix($card, 7, '+1 hour', kind: 'repair-by-hand');

        self::assertTrue($this->build($card)->heads[0]->answered);
    }

    public function test_a_request_of_the_fix_kind_with_no_role_answers_nothing(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $this->review($card, $pullRequest);
        $this->fix($card, 7, '+1 hour', role: null);

        self::assertFalse($this->build($card)->heads[0]->answered);
    }

    /** @return iterable<string, array{string, WorkRequestState, int, string}> */
    public static function fixesThatAnswerNothing(): iterable
    {
        yield 'it settled before the review' => ['agent-review', WorkRequestState::Done, 7, '-1 hour'];
        yield 'it was refused' => ['agent-review', WorkRequestState::Refused, 7, '+1 hour'];
        yield 'it answered failed checks' => ['checks-failed', WorkRequestState::Done, 7, '+1 hour'];
        yield 'it belongs to another pull request' => ['agent-review', WorkRequestState::Done, 8, '+1 hour'];
    }

    #[DataProvider('fixesThatAnswerNothing')]
    public function test_a_fix_round_that_does_not_answer_the_review_changes_nothing(string $reason, WorkRequestState $state, int $number, string $settled): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $this->review($card, $pullRequest);
        $this->fix($card, $number, $settled, $reason, $state);

        $facts = $this->conditionFacts($card, $pullRequest);

        self::assertFalse($this->build($card)->heads[0]->answered);
        self::assertFalse(new AgentReviewDue()->evaluate($facts, []));
        self::assertTrue(new AgentReviewFailed()->evaluate($facts, []));
    }

    private function fix(Card $card, int $number, string $settledAt, string $reason = 'agent-review', WorkRequestState $state = WorkRequestState::Done, string $kind = 'fix', ?string $role = 'fix'): void
    {
        $subject = WorkSubject::card($card->id ?? throw new \LogicException('The card is flushed.'));
        $request = new WorkRequest(AgentCredential::managed($this->em, $this->project, $this->project->id), $subject->type, $subject->id, $card->number, $kind, null, 'fix-agent-review', new \DateTimeImmutable());
        $request->role = $role;
        $request->state = $state;
        $request->settledAt = new \DateTimeImmutable($settledAt);
        $request->context = new WorkRequestContext(pullRequestNumber: $number, reason: $reason);
        $this->em->persist($request);
    }

    /** A posted review of the head aaaa… with the fingerprint change-1, and a new head whose fingerprint is $headChange. */
    private function carry(Card $card, ForgePullRequest $pullRequest, AgentReviewConclusion $conclusion, string $headChange): void
    {
        $review = new AgentReview($card->project, $card, $pullRequest, str_repeat('a', 40), 'Done.', $conclusion, []);
        $review->changeFingerprint = 'change-1';
        $review->postedAt = new \DateTimeImmutable();
        $this->em->persist($review);
        $pullRequest->headSha = $pullRequest->changeFingerprintSha = str_repeat('e', 40);
        $pullRequest->changeFingerprint = $headChange;
    }

    private function conditionFacts(Card $card, ForgePullRequest $pullRequest): Facts
    {
        return FactsMother::facts(
            pullRequest: FactsMother::pullRequest(id: $pullRequest->id ?? throw new \LogicException('The pull request is flushed.')),
            provided: [AgentReviewFacts::class => $this->build($card)],
        );
    }

    public function test_a_review_with_findings_whose_own_check_landed_in_the_hour_needs_a_comment(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $review = $this->review($card, $pullRequest, new \DateTimeImmutable('-10 minutes'));

        $facts = $this->build($card);

        self::assertSame([(string) $review->id], $facts->uncommented);
        self::assertTrue(new AgentReviewUncommented()->evaluate($this->factsOf($pullRequest, $facts), []));
    }

    public function test_a_clean_review_needs_no_comment(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $review = new AgentReview($card->project, $card, $pullRequest, $pullRequest->headSha ?? '', 'Clean.', AgentReviewConclusion::Success, []);
        $review->postedAt = new \DateTimeImmutable();
        $this->em->persist($review);

        self::assertSame([], $this->build($card)->uncommented);
    }

    public function test_a_review_with_no_check_of_its_own_needs_no_comment(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $this->review($card, $pullRequest);

        self::assertSame([], $this->build($card)->uncommented);
    }

    public function test_a_review_whose_check_is_older_than_an_hour_needs_no_comment(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $this->review($card, $pullRequest, new \DateTimeImmutable('-61 minutes'));

        self::assertSame([], $this->build($card)->uncommented);
    }

    public function test_a_review_that_has_its_notice_needs_no_comment(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $review = $this->review($card, $pullRequest, new \DateTimeImmutable());
        $this->em->flush();
        $this->em->persist(new PullRequestNotice($this->project, $pullRequest->id ?? throw new \LogicException('Flushed.'), 'github', $pullRequest->repository, $pullRequest->number, AgentReviewNoticeBody::key($review->id ?? throw new \LogicException('Flushed.')), new \DateTimeImmutable()));

        self::assertSame([], $this->build($card)->uncommented);
    }

    public function test_a_review_carried_to_a_later_head_needs_no_comment(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $review = $this->review($card, $pullRequest, new \DateTimeImmutable('-5 minutes'));
        $review->changeFingerprint = 'change-1';
        $pullRequest->headSha = $pullRequest->changeFingerprintSha = str_repeat('e', 40);
        $pullRequest->changeFingerprint = 'change-1';

        $facts = $this->build($card);

        self::assertSame(AgentReviewConclusion::Failure, $facts->heads[0]->conclusion);
        self::assertSame([], $facts->uncommented);
    }

    public function test_only_the_newest_review_of_a_head_counts(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $older = $this->review($card, $pullRequest, new \DateTimeImmutable('-20 minutes'));
        $newer = new AgentReview($card->project, $card, $pullRequest, $pullRequest->headSha ?? '', 'Newer.', AgentReviewConclusion::Failure, $older->findings(), createdAt: new \DateTimeImmutable('+1 minute'));
        $newer->postedAt = new \DateTimeImmutable('-5 minutes');
        $this->em->persist($newer);

        self::assertSame([(string) $newer->id], $this->build($card)->uncommented);
    }

    public function test_the_fingerprint_changes_when_a_review_needs_a_comment(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $review = $this->review($card, $pullRequest);
        $before = $this->fingerprint($card);

        $review->postedAt = new \DateTimeImmutable();

        self::assertNotEquals($before, $this->fingerprint($card));
    }

    private function factsOf(ForgePullRequest $pullRequest, AgentReviewFacts $facts): Facts
    {
        return FactsMother::facts(
            pullRequest: FactsMother::pullRequest(id: $pullRequest->id ?? throw new \LogicException('The pull request is flushed.')),
            provided: [AgentReviewFacts::class => $facts],
        );
    }

    public function test_every_open_pull_request_is_listed_and_one_without_a_head_has_none(): void
    {
        $card = $this->card($this->project);
        $open = $this->linked($card, 7);
        $this->linked($card, 8, PullRequestState::Merged);
        $headless = $this->linked($card, 9, headSha: null);

        $heads = $this->build($card)->heads;
        self::assertSame([(string) $open->id, (string) $headless->id], array_map(static fn (ReviewedHead $head): string => $head->pullRequestId, $heads));
        self::assertSame('', $heads[1]->headSha);
    }

    public function test_a_review_that_no_check_shows_is_unposted(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        self::assertFalse($this->build($card)->unposted);

        $this->review($card, $pullRequest);
        self::assertTrue($this->build($card)->unposted);

        $this->em->getConnection()->executeStatement('UPDATE agent_reviews SET posted_at = NOW()');
        $this->em->clear();
        self::assertFalse($this->build($this->em->find(Card::class, $card->id) ?? throw new \LogicException('The card exists.'))->unposted);
    }

    public function test_the_fingerprint_changes_with_the_head_and_the_conclusion(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $unreviewed = $this->fingerprint($card);
        $this->review($card, $pullRequest);
        $reviewed = $this->fingerprint($card);
        $pullRequest->headSha = str_repeat('f', 40);
        $moved = $this->fingerprint($card);

        self::assertCount(3, array_unique(array_map(serialize(...), [$unreviewed, $reviewed, $moved])));
        self::assertSame($moved, $this->fingerprint($card));
    }

    private function linked(Card $card, int $number, PullRequestState $state = PullRequestState::Open, ?string $headSha = 'default'): ForgePullRequest
    {
        $pullRequest = $this->pullRequest($this->project, $number);
        $pullRequest->state = $state;
        if ('default' !== $headSha) {
            $pullRequest->headSha = $headSha;
        }
        $this->em->persist(new CardPullRequest($card, 'https://github.com/Acme/Widgets/pull/'.$number, Forge::GitHub, 'Acme/Widgets', $number));

        return $pullRequest;
    }

    private function build(Card $card): AgentReviewFacts
    {
        $this->em->flush();
        $facts = $this->provider()->build($card->snapshot());
        self::assertInstanceOf(AgentReviewFacts::class, $facts);

        return $facts;
    }

    private function fingerprint(Card $card): mixed
    {
        return $this->provider()->fingerprint($this->build($card));
    }

    private function provider(): AgentReviewFactProvider
    {
        $provider = self::getContainer()->get(AgentReviewFactProvider::class);
        self::assertInstanceOf(AgentReviewFactProvider::class, $provider);

        return $provider;
    }
}
