<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

use Symfony\Component\Uid\Uuid;

/** Where a walk of the cards of an experiment stands: the last row of the previous page. */
final readonly class ExperimentCardPosition
{
    public function __construct(
        /** Null for a card with no experiment run, which sorts last. */
        public ?\DateTimeImmutable $lastRunAt,
        public Uuid $cardId,
    ) {
    }
}
