<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

/** The positions, from 0 to 100, of the range and the point of one change on the shared scale of a result block. */
final readonly class ExperimentResultStrip
{
    public function __construct(
        public float $low,
        public float $point,
        public float $high,
    ) {
    }
}
