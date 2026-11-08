<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Scheduler;

use App\Module\Insights\Scheduler\ReportPublicPreviewTask;
use App\Tests\Support\ScheduledTasks;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReportPublicPreviewTaskTest extends KernelTestCase
{
    public function test_it_is_registered_on_the_default_schedule(): void
    {
        self::bootKernel();

        self::assertSame('*/5 * * * *', ScheduledTasks::cronExpressions(self::getContainer())[ReportPublicPreviewTask::class] ?? null);
    }
}
