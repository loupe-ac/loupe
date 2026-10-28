<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

/** What an experiment still reads of one card on its way to a merge, until the experiment reads template metrics. */
final readonly class ExperimentCardOutcome
{
    /** @param array<string, int> $fixRounds reason => fix requests with that reason */
    public function __construct(
        public array $fixRounds = [],
        public bool $merged = false,
        public ?float $hoursToMerge = null,
    ) {
    }

    public function totalFixRounds(): int
    {
        return array_sum($this->fixRounds);
    }
}
