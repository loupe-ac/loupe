<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\EventListener;

use App\Module\Bridge\Command\OpenWorkRequestCommand;
use App\Module\Bridge\Command\OpenWorkRequestHandler;
use App\Module\Bridge\Command\WithdrawWorkRequestCommand;
use App\Module\Bridge\Command\WithdrawWorkRequestHandler;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Event\BridgeAvailabilityChanged;
use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Bridge\WorkSubject\WorkSubjectHandlers;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BlockingRepositories;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Module\Bridge\WorkSubject\RecordingWorkSubjectHandler;
use App\Tests\Support\DispatchedEvents;
use App\Tests\Support\NoCardTitles;
use App\Tests\Support\RecordingAuditor;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class FailUntakenSubjectWorkTest extends KernelTestCase
{
    use BlockingRepositories;
    use BridgeScenario;

    private const string NOW = '2026-10-01 12:00:00';

    private RecordingWorkSubjectHandler $subjects;

    public function test_a_request_with_no_flag_stays_open_with_no_bridge(): void
    {
        $project = $this->scenario('untaken-unflagged');

        $request = $this->open($project, failWhenNoTaker: false);

        self::assertSame(WorkRequestState::Open, $request->state);
        self::assertSame(WorkRequestState::Open, $this->stored($request)->state);
        self::assertSame([], $this->subjects->expired);
    }

    public function test_a_flagged_request_with_no_bridge_expires_at_once(): void
    {
        $project = $this->scenario('untaken-none');
        $changes = DispatchedEvents::of(self::getContainer(), WorkRequestChanged::class);

        $request = $this->open($project);

        self::assertSame(WorkRequestState::Expired, $request->state);
        self::assertSame(WorkRequest::REASON_NO_TAKER, $request->reason);
        self::assertSame([$request], $this->subjects->expired);
        // A listener of the open event hears it before the listener of this test expires the request.
        self::assertSame([WorkRequestState::Open, WorkRequestState::Expired], array_map(static fn (WorkRequestChanged $event): WorkRequestState => $event->state, $changes->events()));
        $stored = $this->stored($request);
        self::assertTrue($stored->failWhenNoTaker);
        self::assertSame([WorkRequestState::Expired, WorkRequest::REASON_NO_TAKER], [$stored->state, $stored->reason]);
    }

    public function test_a_flagged_request_that_no_online_bridge_can_run_expires(): void
    {
        $project = $this->scenario('untaken-not-capable');
        $this->bridge($project, workKinds: ['analysis']);

        $request = $this->open($project);

        self::assertSame([WorkRequestState::Expired, WorkRequest::REASON_NO_CAPABLE_TAKER], [$request->state, $request->reason]);
        self::assertSame([$request], $this->subjects->expired);
    }

    public function test_a_flagged_request_with_a_capable_bridge_stays_open(): void
    {
        $project = $this->scenario('untaken-capable');
        $this->bridge($project);

        $request = $this->open($project);

        self::assertSame(WorkRequestState::Open, $this->stored($request)->state);
        self::assertSame([], $this->subjects->expired);
    }

    public function test_a_flagged_request_with_a_paused_bridge_stays_open(): void
    {
        $project = $this->scenario('untaken-paused');
        $bridge = $this->bridge($project);
        $bridge->pauseRequested = true;
        $this->em()->flush();

        $request = $this->open($project);

        self::assertSame(WorkRequestState::Open, $this->stored($request)->state);
        self::assertSame([], $this->subjects->expired);
    }

    public function test_a_flagged_request_of_a_held_project_stays_open(): void
    {
        $project = $this->scenario('untaken-held');
        $this->repositoryWithGaps($project);

        $request = $this->open($project);

        self::assertSame(WorkRequestState::Open, $this->stored($request)->state);
        self::assertSame([], $this->subjects->expired);
    }

    /** The hold committed, and its listener has not marked the request yet. */
    public function test_a_hold_with_no_mark_on_the_request_keeps_it_open(): void
    {
        $project = $this->scenario('untaken-held-unmarked');
        $request = $this->seedFlagged($project);
        $this->repositoryWithGaps($project);

        $this->dispatch(new BridgeAvailabilityChanged([(string) $project->id]));

        self::assertSame(WorkRequestState::Open, $this->stored($request)->state);
        self::assertSame([], $this->subjects->expired);
    }

    /** The hold ended, and its listener has not restarted the timeout of the request yet. */
    public function test_a_hold_mark_on_the_request_keeps_it_open(): void
    {
        $project = $this->scenario('untaken-held-marked');
        $request = $this->seedFlagged($project);
        $request->workHoldSince = new \DateTimeImmutable(self::NOW);
        $this->em()->flush();

        $this->dispatch(new BridgeAvailabilityChanged([(string) $project->id]));

        self::assertSame(WorkRequestState::Open, $this->stored($request)->state);
        self::assertSame([], $this->subjects->expired);
    }

    /** The mark can land between the read of a caller and the row lock, so the withdrawal reads it under the lock. */
    public function test_a_withdrawal_outside_a_work_hold_reads_the_mark_under_the_row_lock(): void
    {
        $project = $this->scenario('untaken-mark-race');
        $request = $this->seedFlagged($project);
        $id = $request->id ?? throw new \LogicException('A flushed request has an id.');
        // The managed entity stays unmarked, as the copy of a caller that read before the mark does.
        $this->em()->getConnection()->executeStatement('UPDATE work_requests SET work_hold_since = :now WHERE id = :id', ['now' => self::NOW, 'id' => $id->toRfc4122()]);
        $withdraw = self::getContainer()->get(WithdrawWorkRequestHandler::class);
        self::assertInstanceOf(WithdrawWorkRequestHandler::class, $withdraw);

        self::assertFalse($withdraw(new WithdrawWorkRequestCommand($id, WorkRequestState::Expired, onlyIfOpen: true, outsideWorkHoldOnly: true)));

        self::assertSame(WorkRequestState::Open, $this->stored($request)->state);
    }

    /** A heartbeat can commit between the first read of a caller and the lock, so the withdrawal reads the bridges again. */
    #[DataProvider('bridgeChangesThatGiveATaker')]
    public function test_a_withdrawal_while_untaken_reads_the_bridges_again(string $change): void
    {
        $project = $this->scenario('untaken-bridge-race');
        $request = $this->seedFlagged($project);
        // The managed bridge keeps its old values, as the copy of a caller that read before the change does.
        $bridge = $this->bridge($project, workKinds: ['analysis']);
        if (str_contains($change, 'last_seen_at')) {
            $bridge->workKinds = ['analysis:analysis'];
            $bridge->lastSeenAt = new \DateTimeImmutable('2026-10-01 11:00:00');
            $this->em()->flush();
        }
        $this->em()->getConnection()->executeStatement('UPDATE bridges SET '.$change.' WHERE id = :id', ['id' => $bridge->id->toRfc4122()]);

        self::assertFalse($this->withdrawWhileUntaken($request));

        self::assertSame(WorkRequestState::Open, $this->stored($request)->state);
        self::assertSame([], $this->subjects->expired);
    }

    /** @return iterable<string, array{string}> */
    public static function bridgeChangesThatGiveATaker(): iterable
    {
        yield 'a new work kind' => ["work_kinds = '[\"analysis:analysis\"]'"];
        yield 'back from quiet' => ["last_seen_at = '".self::NOW."'"];
    }

    /** The reason can change between the first read and the lock. */
    public function test_a_withdrawal_while_untaken_takes_the_reason_of_its_own_read(): void
    {
        $project = $this->scenario('untaken-reason');
        $request = $this->seedFlagged($project);
        $this->bridge($project, workKinds: ['analysis']);

        self::assertTrue($this->withdrawWhileUntaken($request));

        self::assertSame(WorkRequest::REASON_NO_CAPABLE_TAKER, $this->stored($request)->reason);
    }

    /** The test transaction holds the lock of the expiry, so a second session cannot take the lock of a heartbeat. */
    public function test_an_expiry_locks_out_the_writers_of_bridge_availability(): void
    {
        $project = $this->scenario('untaken-lock');

        $this->open($project);

        $other = DriverManager::getConnection($this->em()->getConnection()->getParams());
        try {
            self::assertFalse((bool) $other->fetchOne('SELECT pg_try_advisory_xact_lock_shared(hashtext(?))', ['bridge-availability:'.$project->owner->id?->toRfc4122()]));
        } finally {
            $other->close();
        }
    }

    public function test_a_change_of_bridge_availability_reads_only_the_named_projects(): void
    {
        $named = $this->scenario('untaken-named');
        $other = $this->project($this->em(), $named->owner, 'Untaken other');
        $expired = $this->seedFlagged($named);
        $untouched = $this->seedFlagged($other);

        $this->dispatch(new BridgeAvailabilityChanged([]));
        self::assertSame(WorkRequestState::Open, $expired->state);

        $this->dispatch(new BridgeAvailabilityChanged([(string) $named->id]));

        self::assertSame([(string) $expired->id], array_map(static fn (WorkRequest $request): string => (string) $request->id, $this->subjects->expired));
        self::assertSame(WorkRequestState::Open, $this->stored($untouched)->state);
    }

    public function test_a_flagged_card_request_is_left_alone(): void
    {
        $project = $this->scenario('untaken-card');
        $request = $this->seedWorkRequest($this->em(), $project, failWhenNoTaker: true);

        $this->dispatch(new BridgeAvailabilityChanged([(string) $project->id]));
        $this->dispatch($this->openEvent($request));

        self::assertSame(WorkRequestState::Open, $this->stored($request)->state);
    }

    /** The listener must build no auditor while it has nothing to expire, so a test can still replace it. */
    public function test_an_availability_change_with_nothing_to_expire_leaves_the_auditor_replaceable(): void
    {
        $project = $this->scenario('untaken-lazy');
        $this->seedWorkRequest($this->em(), $project, subject: $this->analysis());
        $this->dispatch(new BridgeAvailabilityChanged([(string) $project->id]));

        $audit = RecordingAuditor::installedIn(self::getContainer());
        $this->open($project);

        self::assertSame(WorkRequestState::Expired->value, $audit->record('bridge.work_request_withdrawn')->context['state']);
    }

    private function withdrawWhileUntaken(WorkRequest $request): bool
    {
        $withdraw = self::getContainer()->get(WithdrawWorkRequestHandler::class);
        self::assertInstanceOf(WithdrawWorkRequestHandler::class, $withdraw);

        return $withdraw(new WithdrawWorkRequestCommand($request->id ?? throw new \LogicException('A flushed request has an id.'), WorkRequestState::Expired, onlyIfOpen: true, onlyWhileUntaken: true));
    }

    private function scenario(string $name): Project
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $this->subjects = new RecordingWorkSubjectHandler();
        self::getContainer()->set(WorkSubjectHandlers::class, new WorkSubjectHandlers([$this->subjects], new NoCardTitles()));

        return $this->project($this->em(), $this->user($this->em(), $name.'@example.com'), $name);
    }

    private function analysis(): WorkSubject
    {
        return new WorkSubject(RecordingWorkSubjectHandler::TYPE, Uuid::v7());
    }

    private function open(Project $project, bool $failWhenNoTaker = true): WorkRequest
    {
        $handler = self::getContainer()->get(OpenWorkRequestHandler::class);
        self::assertInstanceOf(OpenWorkRequestHandler::class, $handler);

        return $handler(new OpenWorkRequestCommand(
            project: $project,
            subject: $this->analysis(),
            cardNumber: null,
            kind: 'analysis',
            capability: null,
            ruleId: 'analysis',
            context: new WorkRequestContext(),
            failWhenNoTaker: $failWhenNoTaker,
        ));
    }

    private function seedFlagged(Project $project): WorkRequest
    {
        return $this->seedWorkRequest($this->em(), $project, kind: 'analysis', subject: $this->analysis(), failWhenNoTaker: true);
    }

    /** @param list<string> $workKinds */
    private function bridge(Project $project, array $workKinds = ['analysis:analysis']): Bridge
    {
        $bridge = $this->seedBridge($this->em(), $project->owner, projects: [(string) $project->id], lastSeenAt: new \DateTimeImmutable(self::NOW));
        $bridge->capabilities = [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_WAITS];
        $bridge->workKinds = $workKinds;
        $this->em()->flush();

        return $bridge;
    }

    private function openEvent(WorkRequest $request): WorkRequestChanged
    {
        return new WorkRequestChanged(
            $request->project->id ?? throw new \LogicException('A stored project has an id.'),
            $request->subjectType,
            $request->subjectId,
            $request->id ?? throw new \LogicException('A flushed request has an id.'),
            WorkRequestState::Open,
        );
    }

    private function dispatch(object $event): void
    {
        $events = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $events);
        $events->dispatch($event);
    }

    private function stored(WorkRequest $request): WorkRequest
    {
        $this->em()->clear();

        return $this->em()->find(WorkRequest::class, $request->id) ?? throw new \LogicException('The request is stored.');
    }
}
