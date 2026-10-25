<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification\Command\Console;

use App\Module\Notification\Command\Console\GenerateVapidKeysCommand;
use Minishlink\WebPush\VAPID;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class GenerateVapidKeysCommandTest extends TestCase
{
    public function test_it_prints_a_pair_that_the_library_accepts(): void
    {
        $tester = new CommandTester(new GenerateVapidKeysCommand());

        self::assertSame(0, $tester->execute([]));

        $output = $tester->getDisplay();
        $lines = explode("\n", trim($output));
        self::assertCount(2, $lines);
        self::assertStringStartsWith('VAPID_PUBLIC_KEY=', $lines[0]);
        self::assertStringStartsWith('VAPID_PRIVATE_KEY=', $lines[1]);
        $accepted = VAPID::validate([
            'subject' => 'https://example.test',
            'publicKey' => substr($lines[0], \strlen('VAPID_PUBLIC_KEY=')),
            'privateKey' => substr($lines[1], \strlen('VAPID_PRIVATE_KEY=')),
        ]);
        self::assertSame(65, \strlen($accepted['publicKey']));
        self::assertSame(32, \strlen($accepted['privateKey']));
    }

    public function test_each_run_prints_a_new_pair(): void
    {
        $first = new CommandTester(new GenerateVapidKeysCommand());
        $second = new CommandTester(new GenerateVapidKeysCommand());
        $first->execute([]);
        $second->execute([]);

        self::assertNotSame($first->getDisplay(), $second->getDisplay());
    }
}
