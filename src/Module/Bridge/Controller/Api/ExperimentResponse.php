<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use OpenApi\Attributes as OA;

/** The comparison of one experiment, as the spec shows it. ExperimentReportPayload builds the real body. */
#[OA\Schema(required: ['experiment', 'archived', 'baseline', 'confidenceLevel', 'declaredMetrics', 'unknownMetrics', 'unknownGuards', 'minFinishedCards', 'headline', 'variants', 'metrics', 'includedCards', 'leftOutCards'])]
final readonly class ExperimentResponse
{
    private const string RANGE = 'A value and its range at `confidenceLevel`, or null when it has no data.';

    /**
     * @param list<string>|null          $declaredMetrics
     * @param list<string>               $unknownMetrics
     * @param list<string>               $unknownGuards
     * @param array<string, mixed>       $headline
     * @param list<array<string, mixed>> $variants
     * @param list<array<string, mixed>> $metrics
     */
    public function __construct(
        #[OA\Property(description: 'The name of the experiment.')]
        public string $experiment,

        #[OA\Property(description: 'True when the owner archived the experiment and no run has brought it back.')]
        public bool $archived,

        #[OA\Property(description: 'The variant that the rule names as the baseline, or null when the experiment names none.', nullable: true)]
        public ?string $baseline,

        #[OA\Property(description: 'The level of every range in the answer.', maximum: 1, minimum: 0)]
        public float $confidenceLevel,

        #[OA\Property(description: 'The metric keys the rule declares, in their order, or null when it declares none and the default metrics apply.', type: 'array', items: new OA\Items(type: 'string'), nullable: true)]
        public ?array $declaredMetrics,

        #[OA\Property(description: 'The declared metric keys that no metric has.', type: 'array', items: new OA\Items(type: 'string'))]
        public array $unknownMetrics,

        #[OA\Property(description: 'The guard keys that no metric has. The headline leaves them out.', type: 'array', items: new OA\Items(type: 'string'))]
        public array $unknownGuards,

        #[OA\Property(description: 'The finished cards a variant needs before its figure counts.', minimum: 1)]
        public int $minFinishedCards,

        #[OA\Property(description: 'The goal, the baseline, one comparison for each variant other than the baseline, the controls and the guards.', required: ['goal', 'baselineArm', 'comparisons', 'controls', 'guards'], properties: [
            new OA\Property(property: 'goal', description: 'What the experiment tries to improve. `problem` is `unknown-metric` for a goal key that no metric has, `no-direction` for a goal with no better way, else null. With a problem there is no comparison.', required: ['key', 'label', 'valueType', 'direction', 'problem'], properties: [
                new OA\Property(property: 'key', type: 'string'),
                new OA\Property(property: 'label', type: 'string'),
                new OA\Property(property: 'valueType', type: 'string', nullable: true),
                new OA\Property(property: 'direction', description: 'The better way, `lower` or `higher`, or null when the rule names none.', type: 'string', nullable: true, enum: ['lower', 'higher']),
                new OA\Property(property: 'problem', type: 'string', nullable: true, enum: ['unknown-metric', 'no-direction']),
            ], type: 'object'),
            new OA\Property(property: 'baselineArm', description: 'The baseline, or null with no baseline.', required: ['name', 'point', 'cardsBehind'], properties: [
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'point', type: 'number', nullable: true),
                new OA\Property(property: 'cardsBehind', type: 'integer'),
            ], type: 'object', nullable: true),
            new OA\Property(property: 'comparisons', description: 'One entry for each variant other than the baseline, in name order. Empty when the experiment stores no baseline. `state` is `held` when a control on the variant or on the baseline does not pass, and `not-enough-cards` when an arm has fewer finished cards than `minFinishedCards`. `change` is null for those two states. `changeKind` is `relative` for a fraction of the baseline point, and `difference` for a ratio.', type: 'array', items: new OA\Items(required: ['variant', 'state', 'point', 'cardsBehind', 'changeKind', 'change', 'holdReason', 'heldBy', 'guards'], properties: [
                new OA\Property(property: 'variant', type: 'string'),
                new OA\Property(property: 'state', type: 'string', enum: ['not-enough-cards', 'clear', 'not-clear', 'held']),
                new OA\Property(property: 'point', type: 'number', nullable: true),
                new OA\Property(property: 'cardsBehind', type: 'integer'),
                new OA\Property(property: 'changeKind', type: 'string', enum: ['relative', 'difference']),
                new OA\Property(property: 'change', description: self::RANGE, required: ['point', 'low', 'high'], properties: [
                    new OA\Property(property: 'point', type: 'number'),
                    new OA\Property(property: 'low', type: 'number'),
                    new OA\Property(property: 'high', type: 'number'),
                ], type: 'object', nullable: true),
                new OA\Property(property: 'holdReason', type: 'string', nullable: true, enum: ['failed', 'unknown-metric', 'no-data']),
                new OA\Property(property: 'heldBy', description: 'The metric keys of the controls that hold the comparison, or null.', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
                new OA\Property(property: 'guards', type: 'array', items: new OA\Items(required: ['key', 'point', 'change'], properties: [
                    new OA\Property(property: 'key', type: 'string'),
                    new OA\Property(property: 'point', type: 'number', nullable: true),
                    new OA\Property(property: 'change', description: self::RANGE, required: ['point', 'low', 'high'], properties: [
                        new OA\Property(property: 'point', type: 'number'),
                        new OA\Property(property: 'low', type: 'number'),
                        new OA\Property(property: 'high', type: 'number'),
                    ], type: 'object', nullable: true),
                ], type: 'object')),
            ], type: 'object')),
            new OA\Property(property: 'controls', description: 'The checks that the treatment of a variant applied. A control changes no figure and leaves no card out.', type: 'array', items: new OA\Items(required: ['metric', 'variant', 'expect', 'share', 'passingShare', 'cardsWithData', 'cardsFinished', 'result'], properties: [
                new OA\Property(property: 'metric', type: 'string'),
                new OA\Property(property: 'variant', type: 'string'),
                new OA\Property(property: 'expect', description: 'A comparison and a number, such as `> 0`.', type: 'string'),
                new OA\Property(property: 'share', type: 'number'),
                new OA\Property(property: 'passingShare', type: 'number', nullable: true),
                new OA\Property(property: 'cardsWithData', type: 'integer'),
                new OA\Property(property: 'cardsFinished', type: 'integer'),
                new OA\Property(property: 'result', type: 'string', enum: ['passed', 'failed', 'no-data', 'unknown-metric']),
            ], type: 'object')),
            new OA\Property(property: 'guards', description: 'The metrics that the experiment must not worsen. An empty list means no guards.', type: 'array', items: new OA\Items(required: ['key', 'label', 'valueType', 'baselinePoint'], properties: [
                new OA\Property(property: 'key', type: 'string'),
                new OA\Property(property: 'label', type: 'string'),
                new OA\Property(property: 'valueType', type: 'string'),
                new OA\Property(property: 'baselinePoint', type: 'number', nullable: true),
            ], type: 'object')),
        ], type: 'object')]
        public array $headline,

        #[OA\Property(description: 'The variants, by name, over the cards the figures keep.', type: 'array', items: new OA\Items(required: ['name', 'models', 'weight', 'cards', 'finishedCards', 'runs', 'costUsd', 'coveredCostUsd'], properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'models', description: 'Every model its experiment runs asked for, sorted.', type: 'array', items: new OA\Items(type: 'string')),
            new OA\Property(property: 'weight', description: 'Null when the bridge sent none.', type: 'integer', nullable: true),
            new OA\Property(property: 'cards', description: 'The kept cards.', type: 'integer'),
            new OA\Property(property: 'finishedCards', description: 'The kept cards that merged or stand in a terminal column.', type: 'integer'),
            new OA\Property(property: 'runs', description: 'Every worker run of the kept cards.', type: 'integer'),
            new OA\Property(property: 'costUsd', description: 'The cost of those runs in US dollars, or null when no kept card reported usage.', type: 'number', nullable: true),
            new OA\Property(property: 'coveredCostUsd', description: 'The cost of the runs of the experiment of a covered kind, or null.', type: 'number', nullable: true),
        ], type: 'object'))]
        public array $variants,

        #[OA\Property(description: 'The metrics in display order. `byVariant` maps each variant name to its value and range, or to null. `clear` is true when every variant is clear against the baseline, false when one is not, and null with no baseline. `parts` has the same shape, one row for each value that a template count splits by reason, role or kind.', type: 'array', items: new OA\Items(required: ['key', 'label', 'valueType', 'clear', 'byVariant', 'parts'], properties: [
            new OA\Property(property: 'key', type: 'string'),
            new OA\Property(property: 'label', type: 'string'),
            new OA\Property(property: 'valueType', type: 'string'),
            new OA\Property(property: 'clear', type: 'boolean', nullable: true),
            new OA\Property(property: 'byVariant', type: 'object', additionalProperties: new OA\AdditionalProperties(description: self::RANGE, type: 'object', nullable: true)),
            new OA\Property(property: 'parts', type: 'array', items: new OA\Items(type: 'object')),
        ], type: 'object'))]
        public array $metrics,

        #[OA\Property(description: 'The cards the figures keep.', minimum: 0)]
        public int $includedCards,

        #[OA\Property(description: 'The cards the figures leave out.', minimum: 0)]
        public int $leftOutCards,
    ) {
    }
}
