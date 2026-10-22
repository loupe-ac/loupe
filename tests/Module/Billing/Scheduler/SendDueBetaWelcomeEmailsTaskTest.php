<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Scheduler;

use App\Module\Billing\Scheduler\SendDueBetaWelcomeEmailsTask;
use App\Tests\Support\ScheduledTasks;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SendDueBetaWelcomeEmailsTaskTest extends KernelTestCase
{
    public function test_it_runs_every_fifteen_minutes_on_the_default_schedule(): void
    {
        self::bootKernel();

        self::assertSame(
            '*/15 * * * *',
            ScheduledTasks::cronExpressions(self::getContainer())[SendDueBetaWelcomeEmailsTask::class] ?? null,
        );
    }
}
