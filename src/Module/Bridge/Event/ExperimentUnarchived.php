<?php

declare(strict_types=1);

namespace App\Module\Bridge\Event;

use App\Module\Project\Entity\Project;

/** An experiment lost its archive row. Dispatched inside the transaction of the unarchive, after its flush. */
final readonly class ExperimentUnarchived
{
    public function __construct(
        public Project $project,
        public string $experiment,
    ) {
    }
}
