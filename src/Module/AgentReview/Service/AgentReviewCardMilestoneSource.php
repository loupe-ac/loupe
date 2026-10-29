<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Service;

use App\Module\AgentReview\Repository\AgentReviewRepository;
use App\Module\Bridge\Metric\CardMilestone;
use App\Module\Bridge\Metric\CardMilestoneSourceInterface;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\DurationEvent;
use App\Module\Workflow\Contract\TemplateMetricSet;

/** The events that the agent review knows: each review of a card that passed. */
final readonly class AgentReviewCardMilestoneSource implements CardMilestoneSourceInterface
{
    public function __construct(
        private AgentReviewRepository $agentReviews,
    ) {
    }

    #[\Override]
    public function milestonesOf(Project $project, array $cardIds, TemplateMetricSet $templates): array
    {
        $milestones = [];
        foreach ($this->agentReviews->findPassedAtOfCards($project, $cardIds) as $cardId => $times) {
            foreach ($times as $at) {
                $milestones[$cardId][] = new CardMilestone(DurationEvent::AGENT_REVIEW_PASSED, $at);
            }
        }

        return $milestones;
    }
}
