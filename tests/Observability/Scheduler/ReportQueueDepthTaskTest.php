<?php

declare(strict_types=1);

namespace App\Tests\Observability\Scheduler;

use App\Observability\Scheduler\ReportQueueDepthTask;
use App\Tests\Support\ScheduledTasks;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReportQueueDepthTaskTest extends KernelTestCase
{
    public function test_it_is_registered_on_the_default_schedule_every_minute(): void
    {
        self::bootKernel();

        self::assertSame(
            '* * * * *',
            ScheduledTasks::cronExpressions(self::getContainer())[ReportQueueDepthTask::class] ?? null,
        );
    }
}
