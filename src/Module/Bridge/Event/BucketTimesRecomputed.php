<?php

declare(strict_types=1);

namespace App\Module\Bridge\Event;

/** The bucket times of some runs were written again. Dispatched after the commit, with ids only. */
final readonly class BucketTimesRecomputed
{
    public function __construct(
        public string $projectId,
    ) {
    }
}
