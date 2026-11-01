<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Bridge\Metric\CardMilestone;
use App\Module\Bridge\Metric\CardMilestoneSourceInterface;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\DurationEvent;
use App\Module\Workflow\Contract\TemplateMetricSet;

/** The events that the board knows: the pull requests of a card, its ready-to-merge and approval rows, its creation and its moves between columns. */
final readonly class BoardCardMilestoneSource implements CardMilestoneSourceInterface
{
    public function __construct(
        private CardEventRepository $cardEvents,
        private CardPullRequestRepository $cardPullRequests,
    ) {
    }

    #[\Override]
    public function milestonesOf(Project $project, array $cardIds, TemplateMetricSet $templates): array
    {
        if ([] === $cardIds) {
            return [];
        }

        $slotsOfColumn = [];
        foreach ($templates->slotColumns as $slot => $columnId) {
            if (null !== $columnId) {
                $slotsOfColumn[$columnId->toRfc4122()][] = $slot;
            }
        }

        $milestones = [];
        foreach ($this->cardPullRequests->findPullRequestTimesOfCards($project, $cardIds) as $cardId => $times) {
            foreach ($times as $time) {
                if (null !== $time['openedAt']) {
                    $milestones[$cardId][] = new CardMilestone(DurationEvent::PR_OPENED, $time['openedAt']);
                }
                if (null !== $time['mergedAt']) {
                    $milestones[$cardId][] = new CardMilestone(DurationEvent::PR_MERGED, $time['mergedAt']);
                }
            }
        }

        foreach ($this->cardEvents->findKindsOfCards($project, $cardIds, [CardEventKind::ReadyToMerge, CardEventKind::AutoApproved, CardEventKind::Created, CardEventKind::Moved]) as $row) {
            $at = $row['at'];
            if (null === $at) {
                continue;
            }
            if (CardEventKind::ReadyToMerge === $row['kind'] || CardEventKind::AutoApproved === $row['kind']) {
                $milestones[$row['cardId']][] = new CardMilestone(DurationEvent::READY_TO_MERGE, $at);
                continue;
            }
            $sides = CardEventKind::Created === $row['kind'] ? [DurationEvent::ARRIVED => 'column'] : [DurationEvent::ARRIVED => 'to', DurationEvent::LEFT => 'from'];
            foreach ($sides as $event => $side) {
                $column = $row['detail'][$side] ?? null;
                $columnId = \is_array($column) ? $column['id'] ?? null : null;
                foreach (\is_string($columnId) ? $slotsOfColumn[$columnId] ?? [] : [] as $slot) {
                    $milestones[$row['cardId']][] = new CardMilestone($event, $at, $slot);
                }
            }
        }

        return $milestones;
    }
}
