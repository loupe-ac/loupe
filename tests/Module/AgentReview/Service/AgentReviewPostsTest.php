<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Service;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewPost;
use App\Module\AgentReview\Service\AgentReviewCheckPublisher;
use App\Module\AgentReview\Service\AgentReviewPosts;
use App\Module\AgentReview\Service\DuePost;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Project\Entity\Project;
use App\Tests\Module\AgentReview\AgentReviewScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AgentReviewPostsTest extends KernelTestCase
{
    use AgentReviewScenario;

    private const string REVIEWED = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string MERGED = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    private EntityManagerInterface $em;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->project = $this->makeProject('agent-review-posts');
    }

    public function test_a_carried_head_with_no_post_is_due(): void
    {
        $card = $this->card($this->project);
        [$pullRequest, $review] = $this->carried($card);

        $due = $this->due($card);

        self::assertCount(1, $due);
        self::assertSame($review, $due[0]->review);
        self::assertSame($pullRequest, $due[0]->pullRequest);
        self::assertSame(self::MERGED, $due[0]->headSha);
        self::assertTrue($due[0]->carried);
        self::assertNull($due[0]->post);
    }

    public function test_a_carried_head_whose_post_went_out_is_not_due(): void
    {
        $card = $this->card($this->project);
        [, $review] = $this->carried($card);
        $post = new AgentReviewPost($review, strtoupper(self::MERGED), true);
        $post->postedAt = new \DateTimeImmutable();
        $post->checkName = AgentReviewCheckPublisher::NAME;
        $this->em->persist($post);

        self::assertSame([], $this->due($card));
    }

    public function test_a_check_of_the_current_head_under_another_name_is_due_again(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $current = $pullRequest->headSha;
        $pullRequest->headSha = self::REVIEWED;
        $this->review($card, $pullRequest, new \DateTimeImmutable())->checkName = 'loupe/agent-review';
        $pullRequest->headSha = $current;
        $review = $this->review($card, $pullRequest, new \DateTimeImmutable());
        self::assertCount(0, $this->due($card));

        $review->checkName = 'loupe/agent-review';
        $due = $this->due($card);

        self::assertCount(1, $due);
        self::assertSame($review, $due[0]->review);
        self::assertFalse($due[0]->carried);
    }

    public function test_a_post_that_did_not_go_out_is_due_again_with_its_row(): void
    {
        $card = $this->card($this->project);
        [, $review] = $this->carried($card);
        $post = new AgentReviewPost($review, self::MERGED, false);
        $post->checkRunId = 101;
        $this->em->persist($post);

        $due = $this->due($card);

        self::assertCount(1, $due);
        self::assertSame($post, $due[0]->post);
    }

    public function test_a_review_of_the_current_head_is_not_a_carried_post(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $this->review($card, $pullRequest, new \DateTimeImmutable());
        $second = $this->card($this->project, 2);
        $unposted = $this->review($second, $this->linked($second, 8));

        self::assertSame([], $this->due($card));
        $own = $this->due($second);
        self::assertCount(1, $own);
        self::assertFalse($own[0]->carried);
        self::assertSame($unposted, $own[0]->review);
    }

    public function test_an_unposted_review_of_an_older_head_goes_out_on_its_own_head_and_is_carried_too(): void
    {
        $card = $this->card($this->project);
        [, $review] = $this->carried($card, posted: false);

        $due = $this->due($card);

        self::assertSame([[self::REVIEWED, false], [self::MERGED, true]], array_map(static fn (DuePost $post): array => [$post->headSha, $post->carried], $due));
        self::assertSame([$review, $review], array_map(static fn (DuePost $post): AgentReview => $post->review, $due));
    }

    public function test_a_review_of_another_card_is_not_carried_for_this_card(): void
    {
        $card = $this->card($this->project);
        $other = $this->card($this->project, 2);
        $pullRequest = $this->linked($card, 7);
        $this->review($other, $pullRequest, new \DateTimeImmutable())->changeFingerprint = 'change-1';
        $this->moveHead($pullRequest, 'change-1');

        self::assertSame([], $this->due($card));
    }

    public function test_a_different_change_carries_nothing(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $this->review($card, $pullRequest, new \DateTimeImmutable())->changeFingerprint = 'change-1';
        $this->moveHead($pullRequest, 'change-2');

        self::assertSame([], $this->due($card));
    }

    /** @return array{ForgePullRequest, AgentReview} */
    private function carried(Card $card, bool $posted = true): array
    {
        $pullRequest = $this->linked($card, 7);
        $review = $this->review($card, $pullRequest, $posted ? new \DateTimeImmutable() : null);
        $review->changeFingerprint = 'change-1';
        $this->moveHead($pullRequest, 'change-1');

        return [$pullRequest, $review];
    }

    private function moveHead(ForgePullRequest $pullRequest, string $change): void
    {
        $pullRequest->headSha = self::MERGED;
        $pullRequest->changeFingerprintSha = self::MERGED;
        $pullRequest->changeFingerprint = $change;
    }

    private function linked(Card $card, int $number): ForgePullRequest
    {
        $this->em->persist(new CardPullRequest($card, 'https://github.com/acme/widgets/pull/'.$number, Forge::GitHub, 'acme/widgets', $number));

        return $this->pullRequest($card->project, $number);
    }

    /** @return list<DuePost> */
    private function due(Card $card): array
    {
        $this->em->flush();
        $posts = self::getContainer()->get(AgentReviewPosts::class);
        self::assertInstanceOf(AgentReviewPosts::class, $posts);

        return $posts->dueForCard($card);
    }
}
