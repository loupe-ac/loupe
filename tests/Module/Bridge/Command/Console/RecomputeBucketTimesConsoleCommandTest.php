<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command\Console;

use App\Module\Bridge\Messenger\RecomputeBucketTimes;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class RecomputeBucketTimesConsoleCommandTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_the_command_queues_one_recompute_for_each_project(): void
    {
        $kernel = self::bootKernel();
        $em = $this->em();
        $user = $this->user($em, 'recompute-console@example.com');
        $first = $this->project($em, $user, 'Recompute One');
        $second = $this->project($em, $user, 'Recompute Two');

        $tester = new CommandTester(new Application($kernel)->find('app:bridge:recompute-bucket-times'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $transport = self::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $queued = array_map(static fn ($envelope): string => $envelope->getMessage() instanceof RecomputeBucketTimes ? $envelope->getMessage()->projectId : '', $transport->getSent());
        self::assertContains((string) $first->id, $queued);
        self::assertContains((string) $second->id, $queued);
        self::assertStringContainsString('Queued the bucket recompute of', $tester->getDisplay());
    }
}
