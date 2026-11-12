<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

/** The last experiment of a page of the experiment list: the time of its last run, null for none, and its name. */
final readonly class ExperimentListPosition
{
    public function __construct(
        public ?\DateTimeImmutable $lastRunAt,
        public string $name,
    ) {
    }
}
