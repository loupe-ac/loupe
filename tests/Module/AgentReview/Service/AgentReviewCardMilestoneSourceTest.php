<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Service;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Service\AgentReviewCardMilestoneSource;
use App\Module\Bridge\Metric\CardMilestone;
use App\Module\Bridge\Metric\CardMilestoneSourceInterface;
use App\Module\Workflow\Contract\DurationEvent;
use App\Module\Workflow\Contract\TemplateMetricSet;
use App\Tests\Module\AgentReview\AgentReviewScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class AgentReviewCardMilestoneSourceTest extends KernelTestCase
{
    use AgentReviewScenario;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_it_answers_the_passing_reviews_of_the_projects_cards_only(): void
    {
        $project = $this->makeProject('review-milestones');
        $other = $this->makeProject('review-milestones-other');
        $card = $this->card($project, 1);
        $failed = $this->card($project, 2);
        $foreign = $this->card($other, 1);
        $pullRequest = $this->pullRequest($project, 7);
        $foreignPullRequest = $this->pullRequest($other, 7);
        $this->passing($card, $pullRequest, AgentReviewConclusion::Success, '2026-10-01 10:00:00');
        $this->passing($card, $pullRequest, AgentReviewConclusion::Failure, '2026-10-01 09:00:00');
        $this->passing($card, $pullRequest, AgentReviewConclusion::Success, '2026-10-01 12:00:00');
        $this->passing($failed, $pullRequest, AgentReviewConclusion::Failure, '2026-10-01 09:00:00');
        $this->passing($foreign, $foreignPullRequest, AgentReviewConclusion::Success, '2026-10-01 09:00:00');
        $this->em->flush();
        $this->em->clear();

        $source = self::getContainer()->get(AgentReviewCardMilestoneSource::class);
        self::assertInstanceOf(CardMilestoneSourceInterface::class, $source);
        $ids = [$card->id ?? throw new \LogicException(), $failed->id ?? throw new \LogicException(), $foreign->id ?? throw new \LogicException(), Uuid::v7()];
        $milestones = $source->milestonesOf($project, $ids, new TemplateMetricSet());

        self::assertSame([(string) $card->id], array_keys($milestones));
        self::assertEquals([
            new CardMilestone(DurationEvent::AGENT_REVIEW_PASSED, new \DateTimeImmutable('2026-10-01 10:00:00')),
            new CardMilestone(DurationEvent::AGENT_REVIEW_PASSED, new \DateTimeImmutable('2026-10-01 12:00:00')),
        ], $milestones[(string) $card->id]);
        self::assertSame([], $source->milestonesOf($project, [], new TemplateMetricSet()));
        self::assertInstanceOf(AgentReviewCardMilestoneSource::class, $source);
    }

    private function passing(\App\Module\Board\Entity\Card $card, \App\Module\Forge\Entity\ForgePullRequest $pullRequest, AgentReviewConclusion $conclusion, string $at): void
    {
        $this->em->persist(new AgentReview($card->project, $card, $pullRequest, $pullRequest->headSha ?? '', 'Done.', $conclusion, [], createdAt: new \DateTimeImmutable($at)));
    }
}
