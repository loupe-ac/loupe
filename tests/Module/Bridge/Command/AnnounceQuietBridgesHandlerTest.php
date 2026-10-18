<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Bridge\Command\AnnounceQuietBridgesCommand;
use App\Module\Bridge\Command\AnnounceQuietBridgesHandler;
use App\Module\Bridge\Event\BridgeReported;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\RecordedEvents;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/** The default heartbeat interval is 60 seconds, so a bridge goes quiet after 180. */
final class AnnounceQuietBridgesHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-09-23 12:00:00';

    private const array POOL = ['name' => 'default', 'size' => 3, 'inUse' => 0, 'queued' => 0];

    public function test_the_projects_of_a_bridge_that_just_went_quiet_reload(): void
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $em = $this->em();
        $owner = $this->user($em, 'announce-quiet@example.com');
        $followed = $this->project($em, $owner, 'Followed');
        $other = $this->project($em, $owner, 'Other');
        $idle = $this->project($em, $owner, 'Idle');
        $this->seedBridge($em, $owner, projects: [(string) $followed->id], lastSeenAt: new \DateTimeImmutable('2026-09-23 11:56:59'), workerPools: [self::POOL]);
        $this->seedBridge($em, $owner, projects: [(string) $other->id], lastSeenAt: new \DateTimeImmutable('2026-09-23 11:55:00'), workerPools: [self::POOL]);
        $this->seedBridge($em, $owner, projects: [(string) $idle->id], lastSeenAt: new \DateTimeImmutable('2026-09-23 11:54:59'), workerPools: [self::POOL]);
        $this->seedBridge($em, $owner, projects: [(string) $idle->id], lastSeenAt: new \DateTimeImmutable('2026-09-23 11:57:00'), workerPools: [self::POOL]);
        $this->seedBridge($em, $owner, projects: [(string) $idle->id], lastSeenAt: new \DateTimeImmutable('2026-09-23 11:56:00'));

        $told = array_map(static fn (Project $project): string => (string) $project->id, $this->announce());

        self::assertEqualsCanonicalizing([(string) $followed->id, (string) $other->id], $told);
    }

    public function test_the_projects_of_a_bridge_that_just_went_quiet_are_reported(): void
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $em = $this->em();
        $owner = $this->user($em, 'announce-quiet-reported@example.com');
        $followed = $this->project($em, $owner, 'Followed');
        $this->seedBridge($em, $owner, projects: [(string) $followed->id], lastSeenAt: new \DateTimeImmutable('2026-09-23 11:56:59'), workerPools: [self::POOL]);
        $reported = new RecordedEvents();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(BridgeReported::class, $reported);

        $this->announce();

        self::assertSame([[(string) $followed->id]], array_map(static fn (BridgeReported $event): array => $event->projectIds, $reported->of(BridgeReported::class)));
    }

    /** @return list<Project> */
    private function announce(): array
    {
        $handler = self::getContainer()->get(AnnounceQuietBridgesHandler::class);
        self::assertInstanceOf(AnnounceQuietBridgesHandler::class, $handler);

        return $handler(new AnnounceQuietBridgesCommand());
    }
}
