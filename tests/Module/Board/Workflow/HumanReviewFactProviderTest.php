<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Workflow\HumanReviewFactProvider;
use App\Module\Board\Workflow\HumanReviewFacts;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Tests\Module\Board\CardStateFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class HumanReviewFactProviderTest extends KernelTestCase
{
    use CardStateFixtures;

    public function test_a_card_with_its_switch_on_needs_a_person(): void
    {
        self::bootKernel();
        $card = $this->stateCard($this->stateProject('review-fact-own'));
        $card->requiresHumanReview = true;
        $this->em()->flush();

        self::assertTrue($this->needsPerson($card));
    }

    public function test_an_epic_with_no_child_and_the_switch_off_needs_no_person(): void
    {
        self::bootKernel();
        $epic = $this->stateCard($this->stateProject('review-fact-bare'));

        self::assertFalse($this->needsPerson($epic));
    }

    public function test_an_epic_needs_a_person_when_a_child_has_its_switch_on(): void
    {
        self::bootKernel();
        [$epic, $child] = $this->epicWithChild('review-fact-child-switch');
        self::assertFalse($this->needsPerson($epic));

        $stored = $this->em()->find(Card::class, $child->id) ?? throw new \LogicException('The card exists.');
        $stored->requiresHumanReview = true;
        $this->em()->flush();

        self::assertTrue($this->needsPerson($epic));
    }

    public function test_an_epic_needs_no_person_when_a_child_has_no_merged_pull_request(): void
    {
        self::bootKernel();
        [$epic, $child] = $this->epicWithChild('review-fact-child-open');
        $this->childPullRequest($child, 11, PullRequestState::Open);

        self::assertFalse($this->needsPerson($epic));
    }

    public function test_an_epic_needs_a_person_when_a_merged_child_pull_request_has_no_review(): void
    {
        self::bootKernel();
        [$epic, $child] = $this->epicWithChild('review-fact-merged-unreviewed');
        $this->childPullRequest($child, 12, PullRequestState::Merged);

        self::assertTrue($this->needsPerson($epic));
    }

    public function test_an_epic_needs_no_person_when_the_merged_child_pull_request_passed_its_review(): void
    {
        self::bootKernel();
        [$epic, $child] = $this->epicWithChild('review-fact-merged-passed');
        $this->review($child, $this->childPullRequest($child, 13, PullRequestState::Merged), AgentReviewConclusion::Success);

        self::assertFalse($this->needsPerson($epic));
    }

    public function test_an_epic_needs_a_person_when_the_merged_child_review_failed(): void
    {
        self::bootKernel();
        [$epic, $child] = $this->epicWithChild('review-fact-merged-failed');
        $this->review($child, $this->childPullRequest($child, 14, PullRequestState::Merged), AgentReviewConclusion::Failure);

        self::assertTrue($this->needsPerson($epic));
    }

    public function test_an_epic_needs_no_person_when_a_person_overrode_the_failed_review(): void
    {
        self::bootKernel();
        [$epic, $child] = $this->epicWithChild('review-fact-merged-overridden');
        $review = $this->review($child, $this->childPullRequest($child, 15, PullRequestState::Merged), AgentReviewConclusion::Failure);
        $review->overrideAnnouncedFor = 'PRR_1';
        $this->em()->flush();

        self::assertFalse($this->needsPerson($epic));
    }

    public function test_one_unreviewed_pull_request_among_reviewed_ones_needs_a_person(): void
    {
        self::bootKernel();
        [$epic, $child] = $this->epicWithChild('review-fact-merged-mixed');
        $this->review($child, $this->childPullRequest($child, 16, PullRequestState::Merged), AgentReviewConclusion::Success);
        $this->childPullRequest($child, 17, PullRequestState::Merged);

        self::assertTrue($this->needsPerson($epic));
    }

    public function test_a_pull_request_merged_into_another_branch_does_not_count(): void
    {
        self::bootKernel();
        [$epic, $child] = $this->epicWithChild('review-fact-merged-elsewhere');
        $this->childPullRequest($child, 18, PullRequestState::Merged, 'main');

        self::assertFalse($this->needsPerson($epic));
    }

    private function needsPerson(Card $card): bool
    {
        $this->em()->clear();
        $stored = $this->em()->find(Card::class, $card->id) ?? throw new \LogicException('The card exists.');
        $provider = self::getContainer()->get(HumanReviewFactProvider::class);
        self::assertInstanceOf(HumanReviewFactProvider::class, $provider);
        $facts = $provider->build($stored->snapshot());
        self::assertInstanceOf(HumanReviewFacts::class, $facts);

        return $facts->needsPerson;
    }

    /** @return array{Card, Card} */
    private function epicWithChild(string $name): array
    {
        $project = $this->stateProject($name);
        $epic = $this->stateCard($project);
        $child = $this->stateCard($project);
        $child->parent = $epic;
        $this->em()->flush();

        return [$epic, $child];
    }

    private function childPullRequest(Card $child, int $number, PullRequestState $state, ?string $base = null): ForgePullRequest
    {
        $pullRequest = new ForgePullRequest($child->project, 'github', 'acme/widgets', $number);
        $pullRequest->headSha = str_repeat('a', 40);
        $pullRequest->state = $state;
        $pullRequest->baseBranch = $base ?? 'epic/'.$child->parent?->number;
        $this->em()->persist($pullRequest);
        $link = new CardPullRequest($child, 'https://github.com/acme/widgets/pull/'.$number, Forge::GitHub, 'acme/widgets', $number);
        $child->pullRequests->add($link);
        $this->em()->persist($link);
        $this->em()->flush();

        return $pullRequest;
    }

    private function review(Card $child, ForgePullRequest $pullRequest, AgentReviewConclusion $conclusion): AgentReview
    {
        $review = new AgentReview(
            project: $child->project,
            card: $child,
            pullRequest: $pullRequest,
            headSha: $pullRequest->headSha ?? '',
            summary: 'Reviewed.',
            conclusion: $conclusion,
            findings: [],
        );
        $this->em()->persist($review);
        $this->em()->flush();

        return $review;
    }
}
