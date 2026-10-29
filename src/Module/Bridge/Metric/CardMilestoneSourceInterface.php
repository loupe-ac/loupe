<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\TemplateMetricSet;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Uid\Uuid;

/**
 * The dated events of cards that one module knows. Bridge may not import Board or AgentReview, so each of them
 * answers for the events it owns, and a duration metric reads them all.
 */
#[AutoconfigureTag(CardMilestoneSourceInterface::TAG)]
interface CardMilestoneSourceInterface
{
    public const string TAG = 'app.card_milestone_source';

    /**
     * The events of the project's cards with these ids, in no set order. A card with none has no key.
     *
     * @param list<Uuid> $cardIds
     *
     * @return array<string, list<CardMilestone>> card id => events
     */
    public function milestonesOf(Project $project, array $cardIds, TemplateMetricSet $templates): array;
}
