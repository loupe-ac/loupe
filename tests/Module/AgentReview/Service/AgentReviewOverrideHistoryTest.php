<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Service;

use App\Module\AgentReview\Service\AgentReviewOverrideHistory;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Project\Entity\Project;
use App\Tests\Module\AgentReview\AgentReviewScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AgentReviewOverrideHistoryTest extends KernelTestCase
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
        $this->project = $this->makeProject('agent-review-override-history');
    }

    public function test_an_override_writes_one_entry_and_a_repeat_writes_none(): void
    {
        [$card, $pullRequest] = $this->overridden();

        $this->sync($pullRequest);
        $this->sync($pullRequest);

        $events = $this->events($card);
        self::assertCount(1, $events);
        self::assertSame(CardEventKind::ReviewOverridden, $events[0]->kind);
        self::assertSame(['pullRequest' => 7, 'approver' => 'ubermuda', 'headSha' => str_repeat('a', 40)], $events[0]->detail);
    }

    public function test_a_second_approval_after_the_first_writes_a_second_entry_only_when_it_is_a_new_approval(): void
    {
        [$card, $pullRequest] = $this->overridden();
        $this->sync($pullRequest);

        $pullRequest->latestApprovalId = 'PRR_2';
        $this->sync($pullRequest);

        self::assertCount(2, $this->events($card));
    }

    public function test_a_withdrawn_approval_writes_the_end_once(): void
    {
        [$card, $pullRequest] = $this->overridden();
        $this->sync($pullRequest);

        $pullRequest->review = PullRequestReview::None;
        $pullRequest->latestApprovedAt = $pullRequest->latestApprovalId = $pullRequest->latestApproverLogin = null;
        $this->sync($pullRequest);
        $this->sync($pullRequest);

        $kinds = array_map(static fn (CardEvent $event): CardEventKind => $event->kind, $this->events($card));
        self::assertSame([CardEventKind::ReviewOverridden, CardEventKind::ReviewOverrideEnded], $kinds);
    }

    public function test_a_review_that_stands_writes_nothing(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card);
        $this->review($card, $pullRequest);

        $this->sync($pullRequest);

        self::assertSame([], $this->events($card));
    }

    /** @return array{Card, ForgePullRequest} */
    private function overridden(): array
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card);
        $this->review($card, $pullRequest);
        $pullRequest->review = PullRequestReview::Approved;
        $pullRequest->coveredSha = $pullRequest->headSha;
        $pullRequest->latestApprovedAt = new \DateTimeImmutable('+1 hour');
        $pullRequest->latestApprovalId = 'PRR_1';
        $pullRequest->latestApproverLogin = 'ubermuda';

        return [$card, $pullRequest];
    }

    private function linked(Card $card): ForgePullRequest
    {
        $this->em->persist(new CardPullRequest($card, 'https://github.com/acme/widgets/pull/7', Forge::GitHub, 'acme/widgets', 7));

        return $this->pullRequest($card->project, 7);
    }

    private function sync(ForgePullRequest $pullRequest): void
    {
        $this->em->flush();
        $history = self::getContainer()->get(AgentReviewOverrideHistory::class);
        self::assertInstanceOf(AgentReviewOverrideHistory::class, $history);
        $history->sync($pullRequest);
    }

    /** @return list<CardEvent> */
    private function events(Card $card): array
    {
        $this->em->flush();
        $repository = self::getContainer()->get(CardEventRepository::class);
        self::assertInstanceOf(CardEventRepository::class, $repository);

        return array_values(array_filter(
            $repository->findBy(['card' => $card], ['occurredAt' => 'ASC', 'id' => 'ASC']),
            static fn (CardEvent $event): bool => \in_array($event->kind, [CardEventKind::ReviewOverridden, CardEventKind::ReviewOverrideEnded], true),
        ));
    }
}
