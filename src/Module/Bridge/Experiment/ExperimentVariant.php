<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

/** One variant of an experiment, over the cards the report keeps. */
final readonly class ExperimentVariant
{
    /**
     * @param list<string> $models
     */
    public function __construct(
        public string $name,
        /** Every model the experiment runs of the variant asked for, sorted. */
        public array $models,
        /** Null when the bridge reported no weight for this variant. */
        public ?int $weight,
        public int $cards,
        /** Merged, or in a terminal column. */
        public int $finishedCards,
        /** Every worker run of the kept cards. */
        public int $runs,
        /** Millionths of a dollar. Null when no kept card of the variant reported usage. */
        public ?int $costMicros,
        /** Millionths of a dollar, over the covered runs. Null when no kept card of the variant reported usage. */
        public ?int $coveredCostMicros,
    ) {
    }
}
