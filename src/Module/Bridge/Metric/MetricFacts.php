<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

use App\Module\Bridge\Cost\FinishedCard;
use App\Module\Bridge\Entity\WorkerRunFact;
use App\Module\Project\Entity\Project;

/**
 * The worker run facts of one project for one caller: the closed runs of a time span, the cards that finished in a
 * span, and the runs of those cards. The caller makes it, loads it once through {@see MetricRowSource}, reads every
 * window from it and drops it. No window may end after the stamp.
 */
final class MetricFacts
{
    /** @var ?list<WorkerRunFact> the closed runs that ended from to the stamp */
    public ?array $closedRuns = null;

    /** The start of the loaded closed runs. Null with the runs loaded means all time. */
    public ?\DateTimeImmutable $runsFrom = null;

    /** @var ?list<FinishedCard> the cards that finished from to the stamp, oldest first */
    public ?array $finishedCards = null;

    public ?\DateTimeImmutable $cardsFrom = null;

    /** @var array<string, list<WorkerRunFact>> card id => every run of the card */
    public array $runsOfCards = [];

    public function __construct(
        public readonly Project $project,
        /** No window ends after it. */
        public readonly \DateTimeImmutable $to,
    ) {
    }
}
