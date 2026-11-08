<?php

declare(strict_types=1);

namespace App\Module\Bridge\Event;

/** A pin, an exclusion or an inclusion changed which cards an experiment reports. Dispatched after the commit, with ids only. */
final readonly class ExperimentSetupChanged
{
    public function __construct(
        public string $projectId,
        public string $experiment,
    ) {
    }
}
