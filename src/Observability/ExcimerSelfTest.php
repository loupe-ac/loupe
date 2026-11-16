<?php

declare(strict_types=1);

namespace App\Observability;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Runs Excimer the way the Sentry SDK does, so a sandbox whose timer never
 * fires shows up before a profile is dropped for having under 2 samples.
 */
#[AsAlias(ProfilerSelfTest::class)]
final readonly class ExcimerSelfTest implements ProfilerSelfTest
{
    private const float PERIOD = 0.01;

    private const float BUSY_SECONDS = 0.05;

    #[\Override]
    public function sampleCount(): int
    {
        if (!class_exists(\ExcimerProfiler::class)) {
            return 0;
        }

        $profiler = new \ExcimerProfiler();
        $profiler->setEventType(EXCIMER_REAL);
        $profiler->setPeriod(self::PERIOD);
        $profiler->start();

        $end = microtime(true) + self::BUSY_SECONDS;
        while (microtime(true) < $end) {
        }

        $profiler->stop();

        return $profiler->getLog()->getEventCount();
    }
}
