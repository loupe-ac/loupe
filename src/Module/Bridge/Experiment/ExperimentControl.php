<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

/** One control of an experiment, with the share of cards that meet its expectation. */
final readonly class ExperimentControl
{
    public function __construct(
        public string $metric,
        public string $variant,
        /** A comparison and a number, such as "> 0". */
        public string $expect,
        /** The share of cards with data that must meet the expectation, above 0 up to 1. */
        public float $share,
        /** Null when no card has data, or the metric is unknown. */
        public ?float $passingShare,
        public int $cardsWithData,
        public int $cardsFinished,
        public ControlResult $result,
        /** The plain text name of a metric that the workflow template declares, else null. */
        public ?string $templateLabel = null,
    ) {
    }
}
