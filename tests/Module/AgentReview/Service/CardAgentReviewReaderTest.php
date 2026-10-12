<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Service;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\AgentReview\Entity\AgentReviewPost;
use App\Module\AgentReview\Entity\AgentReviewSeverity;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Service\CardAgentReviews;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestReview;
use App\Tests\Module\AgentReview\AgentReviewScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardAgentReviewReaderTest extends KernelTestCase
{
    use AgentReviewScenario;

    private EntityManagerInterface $em;

    private CardAgentReviews $reader;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $reader = self::getContainer()->get(CardAgentReviews::class);
        self::assertInstanceOf(CardAgentReviews::class, $reader);
        $this->reader = $reader;
    }

    public function test_an_overridden_review_names_the_approver_and_links_the_check(): void
    {
        $project = $this->makeProject('agent-review-reader-override');
        $card = $this->card($project);
        $forge = $this->pullRequest($project, 7);
        $link = $this->link($card, 'Acme/Widgets', 7);
        $review = $this->timedReview($card, $forge, new \DateTimeImmutable('2026-10-01T10:00:00+00:00'), 'old');
        $review->checkRunId = 4242;
        $forge->review = PullRequestReview::Approved;
        $forge->coveredSha = $forge->headSha;
        $forge->latestApprovedAt = new \DateTimeImmutable('2026-10-02T10:00:00+00:00');
        $forge->latestApprovalId = 'PRR_1';
        $forge->latestApproverLogin = 'ubermuda';
        $this->em->flush();

        $summary = $this->reader->forCards([$card])[(string) $link->id];

        self::assertSame('ubermuda', $summary['overriddenBy']);
        self::assertSame('https://github.com/acme/widgets/runs/4242', $summary['checkUrl']);
    }

    public function test_an_approval_of_a_newer_head_does_not_override_the_review_of_an_older_head(): void
    {
        $project = $this->makeProject('agent-review-reader-stale-head');
        $card = $this->card($project);
        $forge = $this->pullRequest($project, 7);
        $link = $this->link($card, 'acme/widgets', 7);
        $this->timedReview($card, $forge, new \DateTimeImmutable('2026-10-01T10:00:00+00:00'), 'old');
        $this->approve($forge, str_repeat('b', 40));
        $this->em->flush();

        self::assertNull($this->reader->forCards([$card])[(string) $link->id]['overriddenBy']);
    }

    public function test_an_approval_overrides_a_review_that_the_change_fingerprint_carries_to_the_head(): void
    {
        $project = $this->makeProject('agent-review-reader-carried-head');
        $card = $this->card($project);
        $forge = $this->pullRequest($project, 7);
        $link = $this->link($card, 'acme/widgets', 7);
        $review = $this->timedReview($card, $forge, new \DateTimeImmutable('2026-10-01T10:00:00+00:00'), 'old');
        $review->changeFingerprint = 'same-change';
        $this->approve($forge, str_repeat('b', 40));
        $forge->changeFingerprint = 'same-change';
        $forge->changeFingerprintSha = str_repeat('b', 40);
        $this->em->flush();

        self::assertSame('ubermuda', $this->reader->forCards([$card])[(string) $link->id]['overriddenBy']);
    }

    public function test_a_review_that_stands_has_no_approver(): void
    {
        $project = $this->makeProject('agent-review-reader-stands');
        $card = $this->card($project);
        $forge = $this->pullRequest($project, 7);
        $link = $this->link($card, 'Acme/Widgets', 7);
        $this->timedReview($card, $forge, new \DateTimeImmutable('2026-10-01T10:00:00+00:00'), 'old');
        $this->em->flush();

        $summary = $this->reader->forCards([$card])[(string) $link->id];

        self::assertNull($summary['overriddenBy']);
        self::assertNull($summary['checkUrl']);
    }

    public function test_the_newest_review_of_a_pull_request_wins(): void
    {
        $project = $this->makeProject('agent-review-reader-newest');
        $card = $this->card($project);
        $forge = $this->pullRequest($project, 7);
        $link = $this->link($card, 'Acme/Widgets', 7);
        $this->timedReview($card, $forge, new \DateTimeImmutable('2026-10-01T10:00:00+00:00'), 'old');
        $newest = $this->timedReview($card, $forge, new \DateTimeImmutable('2026-10-02T10:00:00+00:00'), 'new');
        $this->em->flush();

        $summaries = $this->reader->forCards([$card]);

        self::assertCount(1, $summaries);
        $summary = $summaries[(string) $link->id];
        self::assertSame((string) $newest->id, $summary['reviewId']);
        self::assertSame('new', $summary['summary']);
        self::assertSame('failure', $summary['conclusion']);
        self::assertSame(str_repeat('a', 40), $summary['headSha']);
        self::assertSame('2026-10-02T10:00:00+00:00', $summary['createdAt']);
        self::assertNull($summary['postedAt']);
        self::assertSame([], $summary['carriedTo']);
        self::assertSame([['path' => 'src/Foo.php', 'startLine' => 3, 'endLine' => 5, 'severity' => 'important', 'title' => 'Null read', 'body' => 'The value can be null here.', 'category' => 'code', 'needsPerson' => false]], $summary['findings']);
    }

    public function test_the_summary_lists_the_heads_a_review_was_carried_to_oldest_first(): void
    {
        $project = $this->makeProject('agent-review-reader-carried');
        $card = $this->card($project);
        $other = $this->card($project, 2);
        $forge = $this->pullRequest($project, 7);
        $link = $this->link($card, 'acme/widgets', 7);
        $otherLink = $this->link($other, 'acme/widgets', 7);
        $review = $this->review($card, $forge);
        $this->review($other, $forge);
        $later = new AgentReviewPost($review, str_repeat('c', 40), false, new \DateTimeImmutable('2026-10-02T10:00:00+00:00'));
        $earlier = new AgentReviewPost($review, str_repeat('B', 40), true, new \DateTimeImmutable('2026-10-01T10:00:00+00:00'));
        $earlier->postedAt = new \DateTimeImmutable('2026-10-01T10:05:00+00:00');
        $this->em->persist($later);
        $this->em->persist($earlier);
        $this->em->flush();

        $summaries = $this->reader->forCards([$card, $other]);

        self::assertSame([
            ['headSha' => str_repeat('b', 40), 'postedAt' => '2026-10-01T10:05:00+00:00'],
            ['headSha' => str_repeat('c', 40), 'postedAt' => null],
        ], $summaries[(string) $link->id]['carriedTo']);
        self::assertSame([], $summaries[(string) $otherLink->id]['carriedTo']);
    }

    public function test_a_pull_request_with_no_review_has_no_entry(): void
    {
        $project = $this->makeProject('agent-review-reader-none');
        $card = $this->card($project);
        $this->pullRequest($project, 7);
        $this->link($card, 'acme/widgets', 7);
        $this->em->flush();

        self::assertSame([], $this->reader->forCards([$card]));
    }

    public function test_a_link_whose_forge_row_is_missing_has_no_entry(): void
    {
        $project = $this->makeProject('agent-review-reader-missing');
        $card = $this->card($project);
        $this->link($card, 'acme/widgets', 99);
        $this->em->flush();

        self::assertSame([], $this->reader->forCards([$card]));
    }

    public function test_a_review_of_another_card_on_the_same_pull_request_is_not_shown(): void
    {
        $project = $this->makeProject('agent-review-reader-other-card');
        $card = $this->card($project);
        $other = $this->card($project, 2);
        $forge = $this->pullRequest($project, 7);
        $this->link($card, 'acme/widgets', 7);
        $this->review($other, $forge);
        $this->em->flush();

        self::assertSame([], $this->reader->forCards([$card]));
    }

    public function test_many_cards_are_answered_together(): void
    {
        $project = $this->makeProject('agent-review-reader-many');
        $first = $this->card($project);
        $second = $this->card($project, 2);
        $forge = $this->pullRequest($project, 7);
        $firstLink = $this->link($first, 'acme/widgets', 7);
        $secondLink = $this->link($second, 'acme/widgets', 7);
        $this->review($first, $forge);
        $this->review($second, $forge);
        $this->em->flush();

        $summaries = $this->reader->forCards([$first, $second]);

        self::assertSame([(string) $firstLink->id, (string) $secondLink->id], array_keys($summaries));
    }

    private function link(Card $card, string $repository, int $number): CardPullRequest
    {
        $link = new CardPullRequest($card, 'https://github.com/'.$repository.'/pull/'.$number, Forge::GitHub, $repository, $number);
        $card->pullRequests->add($link);
        $this->em->persist($link);

        return $link;
    }

    private function approve(ForgePullRequest $forge, string $head): void
    {
        $forge->headSha = $head;
        $forge->review = PullRequestReview::Approved;
        $forge->coveredSha = $head;
        $forge->latestApprovedAt = new \DateTimeImmutable('2026-10-02T10:00:00+00:00');
        $forge->latestApprovalId = 'PRR_1';
        $forge->latestApproverLogin = 'ubermuda';
    }

    private function timedReview(Card $card, ForgePullRequest $forge, \DateTimeImmutable $createdAt, string $summary): AgentReview
    {
        $review = new AgentReview(
            project: $card->project,
            card: $card,
            pullRequest: $forge,
            headSha: str_repeat('a', 40),
            summary: $summary,
            conclusion: AgentReviewConclusion::Failure,
            findings: [new AgentReviewFinding('src/Foo.php', 3, 5, AgentReviewSeverity::Important, 'Null read', 'The value can be null here.')],
            createdAt: $createdAt,
        );
        $this->em->persist($review);

        return $review;
    }
}
