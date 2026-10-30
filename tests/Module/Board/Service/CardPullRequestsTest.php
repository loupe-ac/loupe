<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Service\CardPullRequests;
use App\Module\Board\Workflow\WorkRequestContexts;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Tests\Module\Workflow\Action\ActionScenario;
use App\Tests\Module\Workflow\Fact\FactsMother;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardPullRequestsTest extends KernelTestCase
{
    use ActionScenario;

    private const string CREATED = '2026-10-10 12:00:00';
    private const string LINKED = '2026-10-12 12:00:00';

    public function test_a_pull_request_merged_before_the_link_is_a_reference(): void
    {
        $card = $this->cardCreatedAt();
        $this->link($card, PullRequestState::Merged, mergedAt: '2026-10-11 12:00:00', linkedAt: self::LINKED);

        self::assertSame([], $this->pullRequests()->forCard($card));
    }

    public function test_a_pull_request_closed_before_the_link_is_a_reference(): void
    {
        $card = $this->cardCreatedAt();
        $this->link($card, PullRequestState::Closed, closedAt: '2026-10-11 12:00:00', linkedAt: self::LINKED);

        self::assertSame([], $this->pullRequests()->forCard($card));
    }

    public function test_a_pull_request_finished_at_the_time_of_the_link_is_a_reference(): void
    {
        $card = $this->cardCreatedAt();
        $this->link($card, PullRequestState::Merged, mergedAt: self::LINKED, linkedAt: self::LINKED);

        self::assertSame([], $this->pullRequests()->forCard($card));
    }

    public function test_a_closed_pull_request_with_no_close_time_is_work(): void
    {
        $card = $this->cardCreatedAt();
        $pullRequest = $this->link($card, PullRequestState::Closed, linkedAt: self::LINKED);

        self::assertSame([$pullRequest], $this->pullRequests()->forCard($card));
    }

    public function test_a_pull_request_that_is_open_at_the_link_is_work(): void
    {
        $card = $this->cardCreatedAt();
        $pullRequest = $this->link($card, PullRequestState::Open, linkedAt: self::LINKED);

        self::assertSame([$pullRequest], $this->pullRequests()->forCard($card));
    }

    public function test_a_pull_request_merged_after_the_link_is_work(): void
    {
        $card = $this->cardCreatedAt();
        $pullRequest = $this->link($card, PullRequestState::Merged, mergedAt: '2026-10-13 12:00:00', linkedAt: self::LINKED);

        self::assertSame([$pullRequest], $this->pullRequests()->forCard($card));
    }

    public function test_a_link_that_predates_link_times_is_a_reference_when_the_pull_request_finished_before_the_card_existed(): void
    {
        $card = $this->cardCreatedAt();
        $this->link($card, PullRequestState::Merged, mergedAt: '2026-10-09 12:00:00', linkedAt: '2026-10-20 12:00:00', predates: true);

        self::assertSame([], $this->pullRequests()->forCard($card));
    }

    public function test_a_link_that_predates_link_times_is_work_when_the_pull_request_merged_after_the_card_was_created(): void
    {
        $card = $this->cardCreatedAt();
        $pullRequest = $this->link($card, PullRequestState::Merged, mergedAt: '2026-10-11 12:00:00', linkedAt: '2026-10-20 12:00:00', predates: true);

        self::assertSame([$pullRequest], $this->pullRequests()->forCard($card));
    }

    public function test_the_earliest_cut_off_of_the_links_to_one_pull_request_decides(): void
    {
        $card = $this->cardCreatedAt();
        $pullRequest = $this->link($card, PullRequestState::Merged, mergedAt: '2026-10-13 12:00:00', linkedAt: self::LINKED);
        $this->em()->persist(new CardPullRequest($card, 'https://github.com/Acme/Widgets/pull/'.$pullRequest->number, Forge::GitHub, 'Acme/Widgets', $pullRequest->number, new \DateTimeImmutable('2026-10-14 12:00:00')));
        $this->em()->flush();

        self::assertSame([$pullRequest], $this->pullRequests()->forCard($card));
    }

    public function test_only_the_work_links_of_a_card_are_returned(): void
    {
        $card = $this->cardCreatedAt();
        $this->link($card, PullRequestState::Merged, mergedAt: '2026-10-11 12:00:00', linkedAt: self::LINKED);
        $work = $this->link($card, PullRequestState::Open, linkedAt: self::LINKED);

        self::assertSame([$work], $this->pullRequests()->forCard($card));
    }

    public function test_links_and_current_urls_still_return_a_reference(): void
    {
        $card = $this->cardCreatedAt();
        $reference = $this->link($card, PullRequestState::Merged, mergedAt: '2026-10-11 12:00:00', linkedAt: self::LINKED);
        $url = 'https://github.com/acme/widgets/pull/'.$reference->number;

        self::assertSame([], $this->pullRequests()->forCard($card));
        self::assertTrue($this->pullRequests()->links($card, 'github', 'acme/widgets', $reference->number));
        self::assertSame([$url], $this->pullRequests()->currentUrls($card));
    }

    public function test_a_work_request_for_a_card_with_only_a_reference_carries_no_pull_request(): void
    {
        $card = $this->cardCreatedAt();
        $reference = $this->link($card, PullRequestState::Merged, mergedAt: '2026-10-11 12:00:00', linkedAt: self::LINKED, headSha: 'abc1234');
        $contexts = $this->service(WorkRequestContexts::class);

        foreach ([null, FactsMother::pullRequest(id: $reference->id)] as $facts) {
            $context = $contexts->forCard($card, $facts);

            self::assertNull($context->pullRequestNumber);
            self::assertNull($context->pullRequestUrl);
            self::assertNull($context->headSha);
        }
    }

    private function pullRequests(): CardPullRequests
    {
        return $this->service(CardPullRequests::class);
    }

    private function cardCreatedAt(): Card
    {
        self::bootKernel();
        $project = $this->workflowProject('reference-links');
        $card = new Card($project, $this->column($project, 'in-progress'), 'Card', '', ++$this->cardNumber, createdAt: new \DateTimeImmutable(self::CREATED));
        $this->em()->persist($card);
        $this->em()->flush();

        return $card;
    }

    private function link(
        Card $card,
        PullRequestState $state,
        ?string $mergedAt = null,
        ?string $closedAt = null,
        string $linkedAt = self::LINKED,
        bool $predates = false,
        ?string $headSha = 'head1',
    ): ForgePullRequest {
        $number = ++$this->pullRequestNumber;
        $link = new CardPullRequest($card, 'https://github.com/acme/widgets/pull/'.$number, Forge::GitHub, 'acme/widgets', $number, new \DateTimeImmutable($linkedAt));
        $link->predatesLinkTimes = $predates;
        $this->em()->persist($link);
        $pullRequest = new ForgePullRequest($card->project, 'github', 'acme/widgets', $number);
        $pullRequest->state = $state;
        $pullRequest->authorRead = true;
        $pullRequest->headSha = $headSha;
        $pullRequest->mergedAt = null === $mergedAt ? null : new \DateTimeImmutable($mergedAt);
        $pullRequest->closedAt = null === $closedAt ? null : new \DateTimeImmutable($closedAt);
        $this->em()->persist($pullRequest);
        $this->em()->flush();

        return $pullRequest;
    }
}
