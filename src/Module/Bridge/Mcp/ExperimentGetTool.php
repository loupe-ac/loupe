<?php

declare(strict_types=1);

namespace App\Module\Bridge\Mcp;

use App\Module\Bridge\Command\ShowExperimentCommand;
use App\Module\Bridge\Command\ShowExperimentHandler;
use App\Module\Bridge\Experiment\ExperimentArm;
use App\Module\Bridge\Experiment\ExperimentCard;
use App\Module\Bridge\Experiment\ExperimentComparison;
use App\Module\Bridge\Experiment\ExperimentControl;
use App\Module\Bridge\Experiment\ExperimentGuard;
use App\Module\Bridge\Experiment\ExperimentGuardChange;
use App\Module\Bridge\Experiment\ExperimentHeadline;
use App\Module\Bridge\Experiment\ExperimentMetric;
use App\Module\Bridge\Experiment\ExperimentVariant;
use App\Module\Bridge\Experiment\GoalState;
use App\Module\Bridge\Experiment\Interval;
use App\Module\Bridge\Experiment\LeftOutReason;
use App\Module\Bridge\Experiment\Stats;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reads the comparison of one experiment, and one page of its cards.
 *
 * @phpstan-type ExperimentRange array{point: float, low: float, high: float}
 * @phpstan-type ExperimentControlRow array{metric: string, variant: string, expect: string, share: float, passingShare: ?float, cardsWithData: int, cardsFinished: int, result: string}
 * @phpstan-type ExperimentComparisonRow array{variant: string, state: string, point: ?float, cardsBehind: int, changeKind: string, change: ExperimentRange|null, holdReason: ?string, heldBy: list<string>|null, guards: list<array{key: string, point: ?float, change: ExperimentRange|null}>}
 * @phpstan-type ExperimentHeadlineRow array{goal: array{key: string, label: string, valueType: ?string, direction: ?string, problem: ?string}, baselineArm: array{name: string, point: ?float, cardsBehind: int}|null, comparisons: list<ExperimentComparisonRow>, controls: list<ExperimentControlRow>, guards: list<array{key: string, label: string, valueType: string, baselinePoint: ?float}>}
 * @phpstan-type ExperimentMetricRow array{key: string, label: string, valueType: string, clear: ?bool, byVariant: array<string, ExperimentRange|null>, parts: list<mixed>}
 * @phpstan-type ExperimentCardRow array{cardId: string, number: ?int, title: ?string, variant: ?string, column: ?string, runs: int, fixRounds: int, costUsd: ?float, coveredCostUsd: ?float, leftOut: list<string>}
 */
#[McpTool(name: self::NAME, description: 'Read the comparison of one experiment of this project, as its Comparison and Cards tabs show it. Pass experiment, the name of the experiment. The response has experiment, baseline (the variant that the rule names as the baseline, or null when the experiment names none), confidenceLevel, declaredMetrics (the metric keys the rule declares, in their order, or null when it declares none and the default metrics apply), unknownMetrics (the declared keys that no metric has), unknownGuards (the guard keys that no metric has, which the headline leaves out), minFinishedCards, headline, variants, metrics, includedCards, leftOutCards and cards. confidenceLevel is the level of every range in the answer: 0.95 for one comparison or none, and 1 - 0.05 / N for N comparisons, so 0.975 for two. A money value is in US dollars, a duration of a built-in metric in milliseconds, a duration of a metric that the workflow template declares in hours, and a ratio is between 0 and 1. Every variant is compared against the baseline, never against another variant. headline has goal, baselineArm, comparisons, guards and controls. goal is what the experiment tries to improve, with key, label and valueType of its metric (valueType is null for an unknown-metric goal), direction (lower or higher, the better way, or null when the rule names none) and problem. problem is unknown-metric when the rule names a goal key that no metric has, no-direction when the rule names a goal with no better way, else null. With a problem there is no comparison. baselineArm is the baseline with name, point and cardsBehind, or null with no baseline. comparisons has one entry for each variant other than the baseline, in name order. comparisons is empty and baseline is null when the experiment stores no baseline, and the figures of variants and metrics then keep their 95 percent ranges and no clear flag. Each comparison has variant, state, point (its goal value), cardsBehind (the cards behind its point: the finished cards for a rate, the delivered cards that have a value for any other metric), changeKind, change, holdReason, heldBy and guards. state is one of not-enough-cards, clear, not-clear or held. held means a control on the variant or on the baseline does not pass, so the comparison names no result, whatever the cards; holdReason is failed, unknown-metric or no-data, the first that any of those controls has in that order, and heldBy lists the metric keys of the controls with that result; both are null for any other state. A control on another variant holds no other comparison. not-enough-cards means the variant or the baseline has fewer than minFinishedCards cards behind its point. clear means the ranges of the variant and the baseline, at confidenceLevel, do not overlap. Otherwise state is not-clear. change is the change of the variant against the baseline, as point, low and high at confidenceLevel, or null when state is held or not-enough-cards, or the baseline point is zero. changeKind relative means change is a fraction of the baseline point, so -0.2 is 20 percent less. changeKind difference is for a goal of valueType ratio, and means change is the difference of the two ratios as a fraction between -1 and 1. A comparison guards entry has key, point (the variant value) and change, the variant minus the baseline as a range in the unit of the metric, or null when an arm has no data. For a ratio the change is a difference of ratios between -1 and 1. headline guards lists the metrics that the experiment must not worsen, each with key, label, valueType and baselinePoint. controls lists the checks that the treatment of a variant applied, each with metric (the key), variant, expect (a comparison and a number, such as "> 0", in the unit of the metric: dollars for a money value, milliseconds for a built-in duration, hours for a template duration), share (the share of cards with data that must meet it), passingShare (the share that do, or null when no card has data or the metric is unknown), cardsWithData, cardsFinished (the finished cards of the variant that the figures keep) and result. A card meets a control with the sum of the metric over its experiment runs. result is passed when passingShare reaches share, failed when it does not, no-data when no finished card has a value, and unknown-metric when the key is no metric of the project. Controls change no figure and leave no card out. Without a goal in the rule the goal is the cost, lower is better. The cost counts every worker run of the card, the covered cost counts the runs of the experiment of a covered kind, and the covered-cost key reads it as a goal, a guard or a metric. Without guards in the rule the guards are the merge rate and the fix rounds, when the template declares them. An empty guard list means no guards. Each variant has name, models (every model its experiment runs asked for, sorted), weight (null when the bridge sent none), cards and finishedCards (the kept cards, and those that merged or stand in a terminal column), runs (every worker run of the kept cards), costUsd (the cost of those runs) and coveredCostUsd (the cost of the runs of the experiment of a covered kind). Each cost is null when no kept card reported usage. Each metric has key, label (the name to show), valueType, clear, byVariant and parts. A metric that the workflow template declares has its own key and label. byVariant maps each variant to point, low and high, the value and its range at confidenceLevel, or to null when the variant has no data. clear is true when every variant other than the baseline is clear against the baseline: both have at least minFinishedCards cards behind their range, and their ranges do not overlap or their points are within 10 percent of the larger one. clear is false when any of those comparisons is not clear, and null when there is no baseline. A rate counts the finished cards, and a value per card counts the cards that ended with the delivered outcome of the template and have the value. A template with no delivered outcome gives no such cards. parts has the same shape, one row per value under a template count that splits by reason, role or kind, with the value type of its metric. includedCards and leftOutCards count the cards the figures keep and leave out. cards lists one page of cards, the latest run first, each with cardId, number, title, variant, column, runs (every worker run of the card), fixRounds, costUsd (every worker run), coveredCostUsd and leftOut. leftOut is empty for a kept card, else its reasons: switched (a run moved the card from a variant the rule no longer offers), mixed (its runs and its pin name more than one variant), before-test (a run with no experiment did a covered kind of work on the card first), covered-without-variant (a run with no experiment did a covered kind of work on the card at or after its first experiment run), no-history (the card history starts after its first experiment run) and no-run (a pin names the card and no experiment run worked it). Filter the cards with variant, which keeps the kept cards of one variant, or with leftOutOnly. The cards are paginated: pass page to walk further, and keep going while hasMore is true. total counts the cards that match the filters. An experiment with no run and no pin in this project is refused.')]
final readonly class ExperimentGetTool
{
    public const string NAME = 'experiment_get';

    public function __construct(
        private BridgeSubjectResolver $subjects,
        private ShowExperimentHandler $showExperiment,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param string      $experiment  the name of the experiment, as worker_run_list and metric_query name it
     * @param int         $page        the 1-based page of the cards to read
     * @param string|null $variant     only the kept cards of this variant
     * @param bool        $leftOutOnly only the cards the figures leave out
     *
     * @return array{experiment: string, baseline: ?string, confidenceLevel: float, declaredMetrics: list<string>|null, unknownMetrics: list<string>, unknownGuards: list<string>, minFinishedCards: int, headline: ExperimentHeadlineRow, variants: list<array{name: string, models: list<string>, weight: ?int, cards: int, finishedCards: int, runs: int, costUsd: ?float, coveredCostUsd: ?float}>, metrics: list<ExperimentMetricRow>, includedCards: int, leftOutCards: int, cards: list<ExperimentCardRow>, page: int, totalPages: int, total: int, hasMore: bool}
     */
    public function __invoke(string $experiment, #[Schema(minimum: 1)] int $page = 1, ?string $variant = null, bool $leftOutOnly = false): array
    {
        try {
            $report = ($this->showExperiment)(new ShowExperimentCommand(
                $this->subjects->requireReadableProject(),
                $experiment,
                $page,
                $variant,
                $leftOutOnly,
            )) ?? throw new ToolCallException(\sprintf('No experiment named "%s" exists in this project. worker_run_list names the experiment of each run.', $experiment));
            $headline = $report->headline ?? throw new \LogicException('A report with metrics has a headline.');

            return [
                'experiment' => $report->experiment,
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
                'cards' => array_map($this->card(...), $report->cards),
                'page' => $report->page,
                'totalPages' => $report->totalPages,
                'total' => $report->filteredTotal,
                'hasMore' => $report->page * ShowExperimentHandler::PER_PAGE < $report->filteredTotal,
            ];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The experiment could not be read. The error has been logged.', previous: $e);
        }
    }

    /** @return ExperimentHeadlineRow */
    private function headline(ExperimentHeadline $headline): array
    {
        $goal = $headline->goal;

        return [
            'goal' => [
                'key' => $goal->metric->key,
                'label' => GoalState::UnknownMetric === $goal->problem ? $goal->metric->key : $this->label($goal->metric),
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
                'label' => $this->label($guard->metric),
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

    /** @return ExperimentRange|null */
    private static function range(?Interval $interval): ?array
    {
        return null === $interval ? null : ['point' => $interval->point, 'low' => $interval->low, 'high' => $interval->high];
    }

    private static function valueType(ExperimentMetric $metric): string
    {
        return ($metric->valueType() ?? throw new \LogicException(\sprintf('The experiment metric %s has no value type.', $metric->key)))->value;
    }

    private function label(ExperimentMetric $metric): string
    {
        return $metric->label ?? $this->translator->trans('bridge.experiments.metric.'.$metric->key);
    }

    /** @return ExperimentMetricRow */
    private function metric(ExperimentMetric $metric, string $valueType, bool $top = false): array
    {
        return [
            'key' => $metric->key,
            'label' => $top ? $this->label($metric) : ($metric->label ?? $metric->key),
            'valueType' => $valueType,
            'clear' => $metric->clear,
            'byVariant' => array_map(self::range(...), $metric->byVariant),
            'parts' => array_map(fn (ExperimentMetric $part): array => $this->metric($part, $valueType), $metric->parts),
        ];
    }

    /** @return ExperimentCardRow */
    private function card(ExperimentCard $card): array
    {
        return [
            'cardId' => $card->cardId->toRfc4122(),
            'number' => $card->number,
            'title' => $card->title,
            'variant' => $card->variant,
            'column' => null === $card->column ? null : $this->translator->trans($card->column->label),
            'runs' => $card->runs,
            'fixRounds' => $card->fixRounds,
            'costUsd' => self::usd($card->costMicros),
            'coveredCostUsd' => self::usd($card->coveredCostMicros),
            'leftOut' => array_map(static fn (LeftOutReason $reason): string => $reason->value, $card->leftOut),
        ];
    }

    private static function usd(?int $micros): ?float
    {
        return null === $micros ? null : $micros / 1_000_000;
    }
}
