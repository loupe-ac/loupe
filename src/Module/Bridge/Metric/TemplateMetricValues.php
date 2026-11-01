<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

use App\Module\Bridge\Experiment\CardReportSourceInterface;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\CountFilter;
use App\Module\Workflow\Contract\DurationEvent;
use App\Module\Workflow\Contract\MetricType;
use App\Module\Workflow\Contract\TemplateMetric;
use App\Module\Workflow\Contract\TemplateMetrics;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Uid\Uuid;

/**
 * The value of a template metric for each card of a sample. A count is the number of matching requests. A rate is
 * 1 when the card matches and 0 when it does not, so the share of 1 values is the rate. A duration is in hours,
 * and a card with no start or no end has no value. Each call reads each source once, whatever the number of cards.
 */
final readonly class TemplateMetricValues
{
    /** @param iterable<CardMilestoneSourceInterface> $milestoneSources */
    public function __construct(
        private WorkRequestRepository $workRequests,
        private CardReportSourceInterface $cardReports,
        private TemplateMetrics $templates,

        #[AutowireIterator(CardMilestoneSourceInterface::TAG)]
        private iterable $milestoneSources,
    ) {
    }

    /**
     * @param list<Uuid> $cardIds
     *
     * @return array<string, int|float|null> card id => value, with a key for every card
     */
    public function valuesOf(Project $project, array $cardIds, TemplateMetric $metric): array
    {
        $values = [];
        if ([] === $cardIds) {
            return $values;
        }

        switch ($metric->type) {
            case MetricType::Count:
                $filter = $metric->filter ?? new CountFilter();
                $counts = $this->countsOf($cardIds);
                foreach ($cardIds as $id) {
                    $values[$id->toRfc4122()] = $counts->matching($id->toRfc4122(), $filter);
                }
                break;
            case MetricType::Rate:
                $values = $this->rateValues($project, $cardIds, $metric);
                break;
            case MetricType::Duration:
                $milestones = $this->milestonesOf($project, $cardIds);
                foreach ($cardIds as $id) {
                    $values[$id->toRfc4122()] = self::hours($milestones[$id->toRfc4122()] ?? [], $metric);
                }
                break;
        }

        return $values;
    }

    /**
     * The requests of the cards, which a split of a count by reason reads.
     *
     * @param list<Uuid> $cardIds
     */
    public function countsOf(array $cardIds): CardRequestCounts
    {
        return CardRequestCounts::fromRows($this->workRequests->findCountsOfCards($cardIds));
    }

    /**
     * The hours from the chosen start to the last end at or after it. The start is the first event of the start
     * name, or the last one when the metric starts at the last. A metric with more starts begins at the earliest
     * event of all of them.
     *
     * @param list<CardMilestone> $milestones
     */
    public static function hours(array $milestones, TemplateMetric $metric): ?float
    {
        $from = $metric->from ?? throw new \LogicException('A duration metric names its start.');
        $to = $metric->to ?? throw new \LogicException('A duration metric names its end.');

        $starts = self::timesOf($milestones, $from);
        foreach ($metric->alsoFrom as $also) {
            $starts = [...$starts, ...self::timesOf($milestones, $also)];
        }
        if ([] === $starts) {
            return null;
        }
        $start = $metric->fromLast ? max($starts) : min($starts);
        $ends = array_filter(self::timesOf($milestones, $to), static fn (int $time): bool => $time >= $start);
        if ([] === $ends) {
            return null;
        }

        return (max($ends) - $start) / 3600;
    }

    /**
     * @param list<Uuid> $cardIds
     *
     * @return array<string, int>
     */
    private function rateValues(Project $project, array $cardIds, TemplateMetric $metric): array
    {
        $values = [];
        if (null !== $metric->outcome) {
            $outcomes = $this->cardReports->outcomesOfCards($project, $cardIds);
            foreach ($cardIds as $id) {
                $values[$id->toRfc4122()] = \in_array($metric->outcome, $outcomes[$id->toRfc4122()] ?? [], true) ? 1 : 0;
            }

            return $values;
        }

        $filter = $metric->filter ?? throw new \LogicException('A rate metric names an outcome or a filter.');
        $counts = $this->countsOf($cardIds);
        foreach ($cardIds as $id) {
            $values[$id->toRfc4122()] = $counts->matching($id->toRfc4122(), $filter) >= 1 ? 1 : 0;
        }

        return $values;
    }

    /**
     * @param list<Uuid> $cardIds
     *
     * @return array<string, list<CardMilestone>>
     */
    private function milestonesOf(Project $project, array $cardIds): array
    {
        $set = $this->templates->forProject($project->id ?? throw new \LogicException('A project has an id here.'));
        $milestones = [];
        foreach ($this->milestoneSources as $source) {
            foreach ($source->milestonesOf($project, $cardIds, $set) as $cardId => $own) {
                $milestones[$cardId] = [...$milestones[$cardId] ?? [], ...$own];
            }
        }

        return $milestones;
    }

    /**
     * @param list<CardMilestone> $milestones
     *
     * @return list<int> timestamps
     */
    private static function timesOf(array $milestones, DurationEvent $event): array
    {
        $times = [];
        foreach ($milestones as $milestone) {
            if ($milestone->event === $event->name && $milestone->slot === $event->slot) {
                $times[] = $milestone->at->getTimestamp();
            }
        }

        return $times;
    }
}
