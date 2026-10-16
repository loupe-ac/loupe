<?php

declare(strict_types=1);

namespace App\Tests\Observability\Command\Console;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ReportQueueDepthConsoleCommandTest extends KernelTestCase
{
    /** The test environment sets no SENTRY_DSN, so the command reports that it sent nothing. */
    public function test_the_command_fails_and_says_so_without_a_usable_dsn(): void
    {
        $kernel = self::bootKernel();

        $tester = new CommandTester(new Application($kernel)->find('app:report-queue-depth'));
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No usable SENTRY_DSN', $tester->getDisplay());
    }
}
