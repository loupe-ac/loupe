<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Repository;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Entity\AgentReviewSeverity;
use App\Module\AgentReview\Repository\AgentReviewRepository;
use App\Module\Board\Entity\Card;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Project\Entity\Project;
use App\Tests\Module\AgentReview\AgentReviewScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AgentReviewRepositoryTest extends KernelTestCase
{
    use AgentReviewScenario;

    private EntityManagerInterface $em;

    private AgentReviewRepository $reviews;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $reviews = self::getContainer()->get(AgentReviewRepository::class);
        self::assertInstanceOf(AgentReviewRepository::class, $reviews);
        $this->reviews = $reviews;
    }

    public function test_find_latest_of_heads_answers_the_newest_review_of_the_current_head_only(): void
    {
        $project = $this->makeProject('agent-review-latest');
        $card = $this->card($project);
        $pullRequest = $this->pullRequest($project, 7);
        $reviewed = $this->pullRequest($project, 8);
        $unreviewed = $this->pullRequest($project, 9);
        $noHead = new ForgePullRequest($project, 'github', 'acme/widgets', 10);
        $this->em->persist($noHead);

        $reviewed->headSha = str_repeat('c', 40);
        $stale = $this->reviewAt($card, $pullRequest, str_repeat('d', 40), '2026-10-01 09:00:00');
        $older = $this->reviewAt($card, $reviewed, str_repeat('C', 40), '2026-10-01 09:00:00');
        $newer = $this->reviewAt($card, $reviewed, str_repeat('c', 40), '2026-10-01 10:00:00');
        $this->em->flush();

        $latest = $this->reviews->findLatestOfHeads([$pullRequest, $reviewed, $unreviewed, $noHead]);

        self::assertSame([(string) $reviewed->id => $newer], $latest);
        self::assertNotSame($older, $latest[(string) $reviewed->id]);
        self::assertNotContains($stale, $latest);
        self::assertSame([], $this->reviews->findLatestOfHeads([]));
    }

    public function test_find_latest_of_heads_matches_a_review_of_an_older_head_with_the_same_change(): void
    {
        $project = $this->makeProject('agent-review-latest-carried');
        $card = $this->card($project);
        $pullRequest = $this->fingerprinted($project, 7, str_repeat('e', 40), 'change-1');
        $carried = $this->reviewAt($card, $pullRequest, str_repeat('d', 40), '2026-10-01 09:00:00', 'change-1');
        $this->em->flush();

        self::assertSame([(string) $pullRequest->id => $carried], $this->reviews->findLatestOfHeads([$pullRequest]));
    }

    public function test_find_latest_of_heads_reads_the_fingerprint_head_in_any_case(): void
    {
        $project = $this->makeProject('agent-review-latest-case');
        $card = $this->card($project);
        $pullRequest = $this->fingerprinted($project, 7, str_repeat('e', 40), 'change-1');
        $pullRequest->changeFingerprintSha = str_repeat('E', 40);
        $carried = $this->reviewAt($card, $pullRequest, str_repeat('d', 40), '2026-10-01 09:00:00', 'change-1');
        $this->em->flush();

        self::assertSame([(string) $pullRequest->id => $carried], $this->reviews->findLatestOfHeads([$pullRequest]));
    }

    public function test_find_latest_of_heads_never_matches_on_a_null_fingerprint(): void
    {
        $project = $this->makeProject('agent-review-latest-null');
        $card = $this->card($project);
        $pullRequest = $this->fingerprinted($project, 7, str_repeat('e', 40), null);
        $this->reviewAt($card, $pullRequest, str_repeat('d', 40), '2026-10-01 09:00:00');
        $this->em->flush();

        self::assertSame([], $this->reviews->findLatestOfHeads([$pullRequest]));
    }

    public function test_find_latest_of_heads_ignores_a_fingerprint_of_another_head(): void
    {
        $project = $this->makeProject('agent-review-latest-stale-print');
        $card = $this->card($project);
        $pullRequest = $this->fingerprinted($project, 7, str_repeat('e', 40), 'change-1');
        $pullRequest->changeFingerprintSha = str_repeat('d', 40);
        $this->reviewAt($card, $pullRequest, str_repeat('d', 40), '2026-10-01 09:00:00', 'change-1');
        $this->em->flush();

        self::assertSame([], $this->reviews->findLatestOfHeads([$pullRequest]));
    }

    public function test_find_latest_of_heads_matches_each_fingerprint_on_its_own_pull_request_only(): void
    {
        $project = $this->makeProject('agent-review-latest-cross');
        $card = $this->card($project);
        $first = $this->fingerprinted($project, 7, str_repeat('e', 40), 'change-1');
        $second = $this->fingerprinted($project, 8, str_repeat('f', 40), 'change-1');
        $own = $this->reviewAt($card, $first, str_repeat('d', 40), '2026-10-01 09:00:00', 'change-1');
        $this->em->flush();

        self::assertSame([(string) $first->id => $own], $this->reviews->findLatestOfHeads([$first, $second]));
    }

    public function test_find_latest_of_heads_takes_the_newest_of_a_head_match_and_a_fingerprint_match(): void
    {
        $project = $this->makeProject('agent-review-latest-newest');
        $card = $this->card($project);
        $byHead = $this->fingerprinted($project, 7, str_repeat('e', 40), 'change-1');
        $byPrint = $this->fingerprinted($project, 8, str_repeat('e', 40), 'change-2');
        $this->reviewAt($card, $byHead, str_repeat('d', 40), '2026-10-01 09:00:00', 'change-1');
        $headReview = $this->reviewAt($card, $byHead, str_repeat('e', 40), '2026-10-01 10:00:00', 'change-1');
        $this->reviewAt($card, $byPrint, str_repeat('e', 40), '2026-10-01 09:00:00', 'change-2');
        $printReview = $this->reviewAt($card, $byPrint, str_repeat('d', 40), '2026-10-01 10:00:00', 'change-2');
        $this->em->flush();

        self::assertSame([(string) $byHead->id => $headReview, (string) $byPrint->id => $printReview], $this->reviews->findLatestOfHeads([$byHead, $byPrint]));
    }

    private function fingerprinted(Project $project, int $number, string $headSha, ?string $change): ForgePullRequest
    {
        $pullRequest = $this->pullRequest($project, $number);
        $pullRequest->headSha = $headSha;
        $pullRequest->changeFingerprintSha = $headSha;
        $pullRequest->changeFingerprint = $change;

        return $pullRequest;
    }

    private function reviewAt(Card $card, ForgePullRequest $pullRequest, string $headSha, string $createdAt, ?string $change = null): AgentReview
    {
        $review = new AgentReview($card->project, $card, $pullRequest, $headSha, 'Done.', AgentReviewConclusion::Success, [], createdAt: new \DateTimeImmutable($createdAt));
        $review->changeFingerprint = $change;
        $this->em->persist($review);

        return $review;
    }

    public function test_find_unposted_answers_the_unposted_reviews_of_the_named_pull_requests_only(): void
    {
        $project = $this->makeProject('agent-review-unposted');
        $card = $this->card($project);
        $named = $this->pullRequest($project, 7);
        $other = $this->pullRequest($project, 8);
        $first = $this->review($card, $named);
        $posted = $this->review($card, $named, new \DateTimeImmutable());
        $elsewhere = $this->review($card, $other);
        $this->em->flush();

        self::assertSame([$first], $this->reviews->findUnpostedOfCard($card, [$named]));
        $both = $this->reviews->findUnpostedOfCard($card, [$named, $other]);
        self::assertCount(2, $both);
        self::assertContains($first, $both);
        self::assertContains($elsewhere, $both);
        self::assertNotContains($posted, $both);
    }

    public function test_find_unposted_answers_nothing_for_no_pull_request(): void
    {
        $project = $this->makeProject('agent-review-unposted-none');
        self::assertSame([], $this->reviews->findUnpostedOfCard($this->card($project), []));
    }

    public function test_find_unposted_leaves_out_the_reviews_of_another_card_on_the_same_pull_request(): void
    {
        $project = $this->makeProject('agent-review-unposted-card');
        $card = $this->card($project);
        $pullRequest = $this->pullRequest($project, 9);
        $own = $this->review($card, $pullRequest);
        $this->review($this->card($project, 2), $pullRequest);
        $this->em->flush();

        self::assertSame([$own], $this->reviews->findUnpostedOfCard($card, [$pullRequest]));
    }

    public function test_the_findings_survive_a_round_trip_through_the_database(): void
    {
        $project = $this->makeProject('agent-review-round-trip');
        $review = $this->review($this->card($project), $this->pullRequest($project));
        $this->em->flush();
        $id = $review->id;
        $this->em->clear();

        $stored = $this->em->find(AgentReview::class, $id);
        self::assertInstanceOf(AgentReview::class, $stored);
        $findings = $stored->findings();
        self::assertCount(1, $findings);
        self::assertSame('src/Foo.php', $findings[0]->path);
        self::assertSame(3, $findings[0]->startLine);
        self::assertSame(5, $findings[0]->endLine);
        self::assertSame(AgentReviewSeverity::Important, $findings[0]->severity);
        self::assertSame('Null read', $findings[0]->title);
        self::assertSame('The value can be null here.', $findings[0]->body);
    }

    public function test_delete_by_project_spares_the_reviews_of_another_project(): void
    {
        $doomed = $this->makeProject('agent-review-doomed');
        $spared = $this->makeProject('agent-review-spared');
        $this->review($this->card($doomed), $this->pullRequest($doomed));
        $kept = $this->review($this->card($spared), $this->pullRequest($spared));
        $this->em->flush();

        self::assertSame(1, $this->reviews->deleteByProject($doomed));

        $this->em->clear();
        self::assertSame(0, $this->reviews->count(['project' => $doomed->id]));
        self::assertNotNull($this->em->find(AgentReview::class, $kept->id));
    }
}
