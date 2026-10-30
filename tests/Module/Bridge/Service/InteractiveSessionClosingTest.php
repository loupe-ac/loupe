<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\CloseStaleInteractiveRunsCommand;
use App\Module\Bridge\Command\CloseStaleInteractiveRunsHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Messenger\CollectSessionUsage;
use App\Module\Bridge\Service\InteractiveRuns;
use App\Module\Bridge\Service\InteractiveStaleClose;
use App\Module\Bridge\ValueObject\WorkerRunCloseReason;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\FeatureFlags;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class InteractiveSessionClosingTest extends KernelTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-10-28 12:00:00';

    public function test_a_session_close_reaches_every_project_of_the_owner_and_no_other_owner(): void
    {
        [$owner, $first] = $this->scenario('close-session');
        $second = $this->project($this->em(), $owner, 'Second project');
        $stranger = $this->project($this->em(), $this->user($this->em(), 'close-session-stranger@example.com'), 'Stranger project');
        $sessionId = Uuid::v4();
        $one = $this->runs()->open($first, Uuid::v7(), 1, $sessionId, 'design');
        $two = $this->runs()->open($second, Uuid::v7(), 2, $sessionId, 'design');
        $other = $this->runs()->open($stranger, Uuid::v7(), 3, $sessionId, 'design');
        $unrelated = $this->runs()->open($first, Uuid::v7(), 4, Uuid::v4(), 'design');

        $closed = $this->runs()->closeSession($owner, $sessionId, WorkerRunCloseReason::SessionEnded);

        self::assertSame(2, $closed);
        self::assertSame(WorkerRunCloseReason::SessionEnded, $this->reload($one)->closeReason);
        self::assertSame(WorkerRunState::Closed, $this->reload($two)->state);
        self::assertSame(WorkerRunState::Running, $this->reload($other)->state);
        self::assertSame(WorkerRunState::Running, $this->reload($unrelated)->state);
        self::assertCount(2, $this->usageRequests());
    }

    public function test_a_session_with_no_open_run_closes_nothing(): void
    {
        [$owner] = $this->scenario('close-session-none');

        self::assertSame(0, $this->runs()->closeSession($owner, Uuid::v4(), WorkerRunCloseReason::SessionEnded));
    }

    public function test_the_existing_close_paths_record_their_reason(): void
    {
        [, $project] = $this->scenario('close-reasons');
        $cardId = Uuid::v7();
        $bySession = Uuid::v4();
        $byPerson = $this->runs()->open($project, $cardId, 1, Uuid::v4(), 'design');
        $this->runs()->open($project, $cardId, 1, $bySession, 'review');
        $moved = $this->runs()->open($project, Uuid::v7(), 2, Uuid::v4(), 'design');

        $this->runs()->close($project, $cardId, $bySession);
        self::assertNotNull($byPerson->id);
        $this->runs()->closeById($project, $byPerson->id);
        $this->runs()->closeOnMove($project, [$moved->cardId() ?? throw new \LogicException('A card run has a card.')]);

        self::assertSame(WorkerRunCloseReason::Person, $this->reload($byPerson)->closeReason);
        self::assertSame(WorkerRunCloseReason::CardMoved, $this->reload($moved)->closeReason);
    }

    public function test_the_stale_close_counts_from_the_last_activity_or_else_from_the_start(): void
    {
        [, $project] = $this->scenario('close-stale');
        $quiet = $this->runs()->open($project, Uuid::v7(), 1, Uuid::v4(), 'design');
        $active = $this->runs()->open($project, Uuid::v7(), 2, Uuid::v4(), 'design');
        $young = $this->runs()->open($project, Uuid::v7(), 3, Uuid::v4(), 'design');
        $this->setTimes($quiet, '2026-10-27 10:00:00', null);
        $this->setTimes($active, '2026-10-27 10:00:00', '2026-10-28 06:00:00');
        $this->setTimes($young, '2026-10-28 01:00:00', null);
        $quietBefore = new \DateTimeImmutable('2026-10-28 00:00:00');

        $closed = $this->runs()->closeStale($quietBefore, 10);

        self::assertSame([(string) $quiet->id], array_map(static fn (WorkerRun $run): string => (string) $run->id, $closed));
        self::assertSame(WorkerRunCloseReason::Stale, $this->reload($quiet)->closeReason);
        self::assertSame(WorkerRunState::Running, $this->reload($active)->state);
        self::assertSame(WorkerRunState::Running, $this->reload($young)->state);
    }

    public function test_the_stale_sweep_does_nothing_while_its_threshold_is_zero(): void
    {
        [, $project] = $this->scenario('close-stale-off');
        $run = $this->runs()->open($project, Uuid::v7(), 1, Uuid::v4(), 'design');
        $this->setTimes($run, '2026-10-01 10:00:00', null);
        $clock = self::getContainer()->get('clock');
        self::assertInstanceOf(MockClock::class, $clock);

        $off = new CloseStaleInteractiveRunsHandler($this->runs(), new InteractiveStaleClose(FeatureFlags::service([InteractiveStaleClose::FLAG => 0]), 43200), $clock);
        $on = new CloseStaleInteractiveRunsHandler($this->runs(), new InteractiveStaleClose(FeatureFlags::service([InteractiveStaleClose::FLAG => 43200]), 0), $clock);

        self::assertSame([], $off(new CloseStaleInteractiveRunsCommand()));
        self::assertSame(WorkerRunState::Running, $this->reload($run)->state);
        self::assertCount(1, $on(new CloseStaleInteractiveRunsCommand()));
        self::assertSame(WorkerRunState::Closed, $this->reload($run)->state);
    }

    public function test_the_stale_threshold_follows_its_flag(): void
    {
        $staleClose = new InteractiveStaleClose(FeatureFlags::service([InteractiveStaleClose::FLAG => 3600]), 43200);

        self::assertTrue($staleClose->enabled());
        self::assertSame('2026-10-28 11:00:00', $staleClose->quietBefore(new \DateTimeImmutable(self::NOW))->format('Y-m-d H:i:s'));
    }

    public function test_the_stale_threshold_is_twelve_hours_by_default(): void
    {
        self::bootKernel();
        $staleClose = self::getContainer()->get(InteractiveStaleClose::class);
        self::assertInstanceOf(InteractiveStaleClose::class, $staleClose);

        self::assertSame('2026-10-28 00:00:00', $staleClose->quietBefore(new \DateTimeImmutable(self::NOW))->format('Y-m-d H:i:s'));
    }

    public function test_a_touch_reaches_only_the_runs_of_its_project_and_session_and_writes_once_a_minute(): void
    {
        [$owner, $project] = $this->scenario('touch');
        $other = $this->project($this->em(), $owner, 'Other project');
        $sessionId = Uuid::v4();
        $mine = $this->runs()->open($project, Uuid::v7(), 1, $sessionId, 'design');
        $theirs = $this->runs()->open($other, Uuid::v7(), 2, $sessionId, 'design');
        $at = new \DateTimeImmutable(self::NOW);

        $this->runs()->touch($project, $sessionId, $at);
        $this->runs()->touch($project, $sessionId, $at->modify('+30 seconds'));

        self::assertSame(self::NOW, $this->reload($mine)->lastActivityAt?->format('Y-m-d H:i:s'));
        self::assertNull($this->reload($theirs)->lastActivityAt);

        $this->runs()->touch($project, $sessionId, $at->modify('+90 seconds'));
        self::assertSame('2026-10-28 12:01:30', $this->reload($mine)->lastActivityAt?->format('Y-m-d H:i:s'));
    }

    public function test_a_launched_close_needs_the_bridge_the_owner_and_a_run_older_than_the_grace(): void
    {
        [$owner, $project] = $this->scenario('close-launched');
        $stranger = $this->user($this->em(), 'close-launched-stranger@example.com');
        $bridgeId = Uuid::v4();
        $sessionId = Uuid::v4();
        $run = $this->runs()->open($project, Uuid::v7(), 1, $sessionId, 'design', $bridgeId);
        $this->setTimes($run, '2026-10-28 11:00:00', null);
        $before = new \DateTimeImmutable('2026-10-28 11:58:00');

        self::assertSame(0, $this->runs()->closeLaunched(Uuid::v4(), $owner, $sessionId, $before, WorkerRunCloseReason::SessionGone));
        self::assertSame(0, $this->runs()->closeLaunched($bridgeId, $stranger, $sessionId, $before, WorkerRunCloseReason::SessionGone));
        self::assertSame(0, $this->runs()->closeLaunched($bridgeId, $owner, $sessionId, new \DateTimeImmutable('2026-10-28 10:59:00'), WorkerRunCloseReason::SessionGone));
        self::assertSame(WorkerRunState::Running, $this->reload($run)->state);

        self::assertSame(1, $this->runs()->closeLaunched($bridgeId, $owner, $sessionId, $before, WorkerRunCloseReason::SessionGone));
        self::assertSame(WorkerRunCloseReason::SessionGone, $this->reload($run)->closeReason);
    }

    /** @return array{User, Project} */
    private function scenario(string $name): array
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $this->asyncTransport()->reset();
        $owner = $this->user($this->em(), $name.'@example.com');

        return [$owner, $this->project($this->em(), $owner, 'Project '.substr(md5($name), 0, 8))];
    }

    private function runs(): InteractiveRuns
    {
        $runs = self::getContainer()->get(InteractiveRuns::class);
        self::assertInstanceOf(InteractiveRuns::class, $runs);

        return $runs;
    }

    private function setTimes(WorkerRun $run, string $startedAt, ?string $lastActivityAt): void
    {
        $this->em()->getConnection()->executeStatement(
            'UPDATE bridge_worker_runs SET started_at = :started, last_activity_at = :activity WHERE id = :id',
            ['started' => $startedAt, 'activity' => $lastActivityAt, 'id' => (string) $run->id],
        );
        $this->em()->clear();
    }

    private function reload(WorkerRun $run): WorkerRun
    {
        $this->em()->clear();
        $reloaded = $this->em()->find(WorkerRun::class, $run->id);
        self::assertInstanceOf(WorkerRun::class, $reloaded);

        return $reloaded;
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /** @return list<string> */
    private function usageRequests(): array
    {
        $requests = [];
        foreach ($this->asyncTransport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof CollectSessionUsage) {
                $requests[] = $message->runId;
            }
        }

        return $requests;
    }
}
