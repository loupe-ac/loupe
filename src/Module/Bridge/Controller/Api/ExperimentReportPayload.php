<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\Command\ExperimentReportView;
use App\Module\Bridge\Experiment\ExperimentArm;
use App\Module\Bridge\Experiment\ExperimentComparison;
use App\Module\Bridge\Experiment\ExperimentControl;
use App\Module\Bridge\Experiment\ExperimentGuard;
use App\Module\Bridge\Experiment\ExperimentGuardChange;
use App\Module\Bridge\Experiment\ExperimentHeadline;
use App\Module\Bridge\Experiment\ExperimentMetric;
use App\Module\Bridge\Experiment\ExperimentMetricName;
use App\Module\Bridge\Experiment\ExperimentSummary;
use App\Module\Bridge\Experiment\ExperimentVariant;
use App\Module\Bridge\Experiment\GoalState;
use App\Module\Bridge\Experiment\Interval;
use App\Module\Bridge\Experiment\Stats;

/**
 * The wire form of an experiment: its row in the list, and its comparison.
 *
 * @phpstan-type Range array{point: float, low: float, high: float}
 */
final readonly class ExperimentReportPayload
{
    public function __construct(
        private ExperimentMetricName $names,
    ) {
    }

    /** @return array{name: string, cards: int, lastRunAt: ?string, archived: bool} */
    public function forSummary(ExperimentSummary $summary): array
    {
        return [
            'name' => $summary->name,
            'cards' => $summary->cards,
            'lastRunAt' => $summary->lastRunAt?->format(\DateTimeInterface::ATOM),
            'archived' => $summary->archived,
        ];
    }

    /** @return array<string, mixed> */
    public function forReport(ExperimentReportView $report): array
    {
        $headline = $report->headline ?? throw new \LogicException('A report with metrics has a headline.');

        return [
            'experiment' => $report->experiment,
            'archived' => $report->archived,
            'baseline' => $headline->baseline,
            'confidenceLevel' => $headline->confidence->level,
            'declaredMetrics' => $report->declaredMetrics,
            'unknownMetrics' => $report->unknownMetrics,
            'unknownGuards' => $report->unknownGuards,
            'minFinishedCards' => Stats::MIN_FINISHED_CARDS,
            'headline' => $this->headline($headline),
            'variants' => array_map(static fn (ExperimentVariant $variant): array => [
                'name' => $variant->name,
                'models' => $variant->models,
                'weight' => $variant->weight,
                'cards' => $variant->cards,
                'finishedCards' => $variant->finishedCards,
                'runs' => $variant->runs,
                'costUsd' => self::usd($variant->costMicros),
                'coveredCostUsd' => self::usd($variant->coveredCostMicros),
            ], $report->variants),
            'metrics' => array_values(array_map(
                fn (ExperimentMetric $metric): array => $this->metric($metric, self::valueType($metric), true),
                $report->metrics,
            )),
            'includedCards' => $report->includedCards,
            'leftOutCards' => $report->leftOutCards,
        ];
    }

    /** @return array<string, mixed> */
    private function headline(ExperimentHeadline $headline): array
    {
        $goal = $headline->goal;

        return [
            'goal' => [
                'key' => $goal->metric->key,
                'label' => GoalState::UnknownMetric === $goal->problem ? $goal->metric->key : $this->names->of($goal->metric),
                'valueType' => $goal->metric->valueType()?->value,
                'direction' => $goal->direction?->value,
                'problem' => $goal->problem?->value,
            ],
            'baselineArm' => null === $headline->baselineArm ? null : self::arm($headline->baselineArm),
            'comparisons' => array_map(static fn (ExperimentComparison $comparison): array => [
                'variant' => $comparison->variant,
                'state' => $comparison->state->value,
                'point' => $comparison->arm->point,
                'cardsBehind' => $comparison->arm->finishedCards,
                'changeKind' => $comparison->relative ? 'relative' : 'difference',
                'change' => self::range($comparison->change),
                'holdReason' => $comparison->hold?->reason->value,
                'heldBy' => null === $comparison->hold ? null : array_map(static fn (ExperimentControl $control): string => $control->metric, $comparison->hold->controls),
                'guards' => array_map(static fn (ExperimentGuardChange $guard): array => ['key' => $guard->key, 'point' => $guard->point, 'change' => self::range($guard->change)], $comparison->guards),
            ], $headline->comparisons),
            'controls' => array_map(static fn (ExperimentControl $control): array => [
                'metric' => $control->metric,
                'variant' => $control->variant,
                'expect' => $control->expect,
                'share' => $control->share,
                'passingShare' => $control->passingShare,
                'cardsWithData' => $control->cardsWithData,
                'cardsFinished' => $control->cardsFinished,
                'result' => $control->result->value,
            ], $headline->controls),
            'guards' => array_map(fn (ExperimentGuard $guard): array => [
                'key' => $guard->metric->key,
                'label' => $this->names->of($guard->metric),
                'valueType' => self::valueType($guard->metric),
                'baselinePoint' => $guard->baselinePoint,
            ], $headline->guards),
        ];
    }

    /** @return array{name: string, point: ?float, cardsBehind: int} */
    private static function arm(ExperimentArm $arm): array
    {
        return ['name' => $arm->variant, 'point' => $arm->point, 'cardsBehind' => $arm->finishedCards];
    }

    /** @return Range|null */
    private static function range(?Interval $interval): ?array
    {
        return null === $interval ? null : ['point' => $interval->point, 'low' => $interval->low, 'high' => $interval->high];
    }

    private static function valueType(ExperimentMetric $metric): string
    {
        return ($metric->valueType() ?? throw new \LogicException(\sprintf('The experiment metric %s has no value type.', $metric->key)))->value;
    }

    /** @return array<string, mixed> */
    private function metric(ExperimentMetric $metric, string $valueType, bool $top = false): array
    {
        return [
            'key' => $metric->key,
            'label' => $top ? $this->names->of($metric) : ($metric->label ?? $metric->key),
            'valueType' => $valueType,
            'clear' => $metric->clear,
            'byVariant' => array_map(self::range(...), $metric->byVariant),
            'parts' => array_map(fn (ExperimentMetric $part): array => $this->metric($part, $valueType), $metric->parts),
        ];
    }

    private static function usd(?int $micros): ?float
    {
        return null === $micros ? null : $micros / 1_000_000;
    }
}
