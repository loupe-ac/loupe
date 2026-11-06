<?php

declare(strict_types=1);

namespace App\Module\Bridge\Event;

use App\Module\Project\Entity\Project;

/** An experiment got its archive row. Dispatched inside the transaction of the archive, after its flush. */
final readonly class ExperimentArchiving
{
    public function __construct(
        public Project $project,
        public string $experiment,
    ) {
    }
}
