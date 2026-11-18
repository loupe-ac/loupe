<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Service\BridgeWorkshopConnectionsProvider;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class BridgeWorkshopConnectionsProviderTest extends KernelTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-10-16 12:00:00';

    public function test_each_connection_carries_the_instant_it_turns_quiet_and_reads_quiet_from_then(): void
    {
        self::bootKernel();
        $clock = new MockClock(self::NOW);
        self::getContainer()->set('clock', $clock);
        $em = $this->em();
        $owner = $this->user($em, 'workshop-quiet-at@example.com');
        $project = $this->project($em, $owner, 'Workshop quiet at');
        $older = $this->seedBridge($em, $owner, projects: [(string) $project->id], lastSeenAt: new \DateTimeImmutable('2026-10-16 11:59:00'));
        $newer = $this->seedBridge($em, $owner, projects: [(string) $project->id], lastSeenAt: new \DateTimeImmutable('2026-10-16 11:59:50'));

        $connections = $this->connections($project);

        $olderQuietAt = $connections[(string) $older->id]->quietAt;
        $newerQuietAt = $connections[(string) $newer->id]->quietAt;
        self::assertNotNull($olderQuietAt);
        self::assertNotNull($newerQuietAt);
        self::assertSame(50, $newerQuietAt->getTimestamp() - $olderQuietAt->getTimestamp());
        self::assertFalse($connections[(string) $older->id]->quiet);

        $clock->modify($olderQuietAt->modify('-1 second')->format('Y-m-d H:i:s'));
        $connections = $this->connections($project);
        self::assertFalse($connections[(string) $older->id]->quiet, 'online one second before its time');
        self::assertFalse($connections[(string) $newer->id]->quiet);

        $clock->modify($olderQuietAt->format('Y-m-d H:i:s'));
        $connections = $this->connections($project);
        self::assertTrue($connections[(string) $older->id]->quiet, 'quiet at its time');
        self::assertFalse($connections[(string) $newer->id]->quiet);

        $clock->modify($newerQuietAt->format('Y-m-d H:i:s'));
        self::assertTrue($this->connections($project)[(string) $newer->id]->quiet);
    }

    /** @return array<string, \App\Module\Project\Workshop\WorkshopConnection> */
    private function connections(\App\Module\Project\Entity\Project $project): array
    {
        $provider = self::getContainer()->get(BridgeWorkshopConnectionsProvider::class);
        self::assertInstanceOf(BridgeWorkshopConnectionsProvider::class, $provider);
        $byId = [];
        foreach ($provider->forProject($project) as $connection) {
            $byId[$connection->id] = $connection;
        }

        return $byId;
    }
}
