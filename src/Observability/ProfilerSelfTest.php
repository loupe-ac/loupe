<?php

declare(strict_types=1);

namespace App\Observability;

interface ProfilerSelfTest
{
    /** How many samples the profiler collects over a short busy loop. */
    public function sampleCount(): int;
}
