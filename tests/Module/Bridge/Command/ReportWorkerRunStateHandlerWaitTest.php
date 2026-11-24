<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ReportWorkerRunStateCommand;
use App\Module\Bridge\Command\ReportWorkerRunStateHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\Entity\WorkerWait;
use App\Module\Bridge\Event\ResumableWorkerRunEnded;
use App\Module\Bridge\Messenger\ResumeWaitingSession;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerWaitEndReason;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\DispatchedEvents;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/** A run with a wait ends as waiting, a finished or blocked end closes the wait, and any other end leaves it. */
final class ReportWorkerRunStateHandlerWaitTest extends KernelTestCase
{
    use BridgeScenario;

    private const string BRIDGE = '0199a0e2-9d4c-7c5e-9f2a-3b1c6d7e8f90';
    private const string SESSION = '5f0c2b1e-8d4a-4c3b-9e2f-1a0b3c4d5e6f';

    private User $owner;
    private Project $project;
    private Uuid $cardId;
    private Uuid $runKey;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->owner = $this->user($this->em(), 'report-wait-'.uniqid().'@example.com');
        $this->project = $this->project($this->em(), $this->owner, 'Report wait');
        $this->cardId = $this->seedCard($this->em(), $this->project)->id ?? throw new \LogicException('Seeded.');
        $this->runKey = Uuid::v4();
    }

    /** @return iterable<string, array{string}> */
    public static function parkedEnds(): iterable
    {
        yield 'unfinished' => ['unfinished'];
        yield 'no-result' => ['no-result'];
    }

    #[DataProvider('parkedEnds')]
    public function test_a_run_with_an_open_wait_ends_as_waiting(string $end): void
    {
        $run = $this->runningRun();
        $wait = $this->wait($run);

        $this->end($end);

        $stored = $this->reload($run);
        self::assertSame(WorkerRunState::Waiting, $stored->state);
        self::assertTrue($this->reloadWait($wait)->isOpen());
        self::assertSame(['running', 'waiting'], $this->history($run));
    }

    public function test_the_outcome_fields_stay_as_the_bridge_reported_them(): void
    {
        $run = $this->runningRun();
        $this->wait($run);

        $this->end('unfinished', output: 'parked on the checks');

        $stored = $this->reload($run);
        self::assertSame(0, $stored->exitCode);
        self::assertTrue($stored->hasResult);
        self::assertSame('unfinished', $stored->resultStatus);
        self::assertSame('parked on the checks', $stored->output);
        self::assertNotNull($stored->endedAt);
    }

    public function test_a_wait_that_ended_early_and_has_no_resume_yet_still_makes_the_run_wait(): void
    {
        $run = $this->runningRun();
        $wait = $this->wait($run);
        $wait->end(WorkerWaitEndReason::Condition, new \DateTimeImmutable('2026-09-23 10:03:00'));
        $this->em()->flush();

        $this->end('unfinished');

        self::assertSame(WorkerRunState::Waiting, $this->reload($run)->state);
    }

    public function test_a_wait_that_ended_in_a_way_that_resumes_nothing_leaves_the_state_alone(): void
    {
        $run = $this->runningRun();
        $wait = $this->wait($run);
        $wait->end(WorkerWaitEndReason::CardMoved, new \DateTimeImmutable('2026-09-23 10:03:00'));
        $this->em()->flush();

        $this->end('unfinished');

        self::assertSame(WorkerRunState::Unfinished, $this->reload($run)->state);
    }

    public function test_a_run_with_no_wait_ends_as_reported(): void
    {
        $run = $this->runningRun();

        $this->end('unfinished');

        self::assertSame(WorkerRunState::Unfinished, $this->reload($run)->state);
        self::assertSame(['running', 'unfinished'], $this->history($run));
    }

    public function test_a_retried_report_writes_no_second_history_row(): void
    {
        $run = $this->runningRun();
        $this->wait($run);
        $this->end('unfinished');

        $this->end('unfinished');

        self::assertSame(['running', 'waiting'], $this->history($run));
    }

    public function test_a_retry_after_the_wait_was_resumed_still_finds_its_row(): void
    {
        $run = $this->runningRun();
        $wait = $this->wait($run);
        $this->end('unfinished');
        $wait->end(WorkerWaitEndReason::Condition, new \DateTimeImmutable('2026-09-23 10:06:00'));
        $wait->resumeRunId = Uuid::v7();
        $this->em()->flush();

        $this->end('unfinished');

        self::assertSame(['running', 'waiting'], $this->history($run));
        self::assertSame(WorkerRunState::Waiting, $this->reload($run)->state);
    }

    public function test_a_waiting_run_can_resume_so_its_end_dispatches_the_resumable_event(): void
    {
        $run = $this->runningRun();
        $this->wait($run);
        $ended = DispatchedEvents::of(self::getContainer(), ResumableWorkerRunEnded::class);

        $this->end('unfinished');

        self::assertCount(1, $ended->events());
        self::assertSame(self::SESSION, (string) $ended->events()[0]->sessionId);
    }

    public function test_the_end_of_a_run_asks_for_the_resume_of_a_wait_that_ended_early(): void
    {
        $run = $this->runningRun();
        $wait = $this->wait($run);
        $wait->end(WorkerWaitEndReason::Condition, new \DateTimeImmutable('2026-09-23 10:03:00'));
        $this->em()->flush();
        $transport = self::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        $this->end('unfinished');

        $queued = array_values(array_filter(
            array_map(static fn ($envelope) => $envelope->getMessage(), $transport->getSent()),
            static fn (object $message): bool => $message instanceof ResumeWaitingSession,
        ));
        self::assertCount(1, $queued);
        self::assertSame((string) $wait->id, $queued[0]->waitId);
    }

    public function test_the_end_of_a_run_asks_for_no_resume_of_a_wait_that_a_run_resumed(): void
    {
        $run = $this->runningRun();
        $wait = $this->wait($run);
        $wait->end(WorkerWaitEndReason::Condition, new \DateTimeImmutable('2026-09-23 10:03:00'));
        $wait->resumeRunId = Uuid::v7();
        $this->em()->flush();
        $transport = self::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        $this->end('unfinished');

        self::assertSame([], array_values(array_filter(
            array_map(static fn ($envelope) => $envelope->getMessage(), $transport->getSent()),
            static fn (object $message): bool => $message instanceof ResumeWaitingSession,
        )));
    }

    public function test_the_run_that_continues_a_waiting_run_records_itself_as_the_resume_of_its_ended_wait(): void
    {
        $run = $this->runningRun();
        $wait = $this->wait($run);
        $this->end('unfinished');
        $wait = $this->reloadWait($wait);
        $wait->end(WorkerWaitEndReason::Condition, new \DateTimeImmutable('2026-09-23 10:06:00'));
        $this->em()->flush();
        $resumeKey = Uuid::v4();

        $this->handle(WorkerRunState::Running, continues: $this->runKey, runKey: $resumeKey);

        $resumed = $this->em()->createQueryBuilder()->select('r')->from(WorkerRun::class, 'r')->andWhere('r.runKey = :key')->setParameter('key', $resumeKey, UuidType::NAME)->getQuery()->getOneOrNullResult();
        self::assertNotNull($resumed);
        $this->em()->clear();
        self::assertEquals($resumed->id, $this->em()->find(WorkerWait::class, $wait->id)?->resumeRunId);
    }

    public function test_the_run_that_continues_a_waiting_run_ends_a_wait_that_is_still_open(): void
    {
        $run = $this->runningRun();
        $wait = $this->wait($run);
        $this->end('unfinished');
        $resumeKey = Uuid::v4();

        $this->handle(WorkerRunState::Running, continues: $this->runKey, runKey: $resumeKey);

        $stored = $this->reloadWait($wait);
        self::assertSame(WorkerWaitEndReason::ResumedByPerson, $stored->endReason);
        self::assertNotNull($stored->resumeRunId);
    }

    public function test_a_finished_end_closes_an_open_wait_with_run_finished(): void
    {
        $run = $this->runningRun();
        $wait = $this->wait($run);

        $this->end('succeeded');

        $stored = $this->reloadWait($wait);
        self::assertSame(WorkerRunState::Succeeded, $this->reload($run)->state);
        self::assertFalse($stored->isOpen());
        self::assertSame(WorkerWaitEndReason::RunFinished, $stored->endReason);
        self::assertFalse($stored->awaitsResume());
    }

    public function test_a_blocked_end_closes_an_open_wait_with_run_finished(): void
    {
        $run = $this->runningRun();
        $wait = $this->wait($run);

        $this->end('blocked');

        self::assertSame(WorkerRunState::Blocked, $this->reload($run)->state);
        self::assertSame(WorkerWaitEndReason::RunFinished, $this->reloadWait($wait)->endReason);
    }

    public function test_a_finished_end_keeps_the_reason_of_a_wait_that_already_ended(): void
    {
        $run = $this->runningRun();
        $wait = $this->wait($run);
        $wait->end(WorkerWaitEndReason::TimedOut, new \DateTimeImmutable('2026-09-23 10:03:00'));
        $this->em()->flush();

        $this->end('succeeded');

        self::assertSame(WorkerWaitEndReason::TimedOut, $this->reloadWait($wait)->endReason);
        self::assertSame(WorkerRunState::Succeeded, $this->reload($run)->state);
    }

    /** @return iterable<string, array{string, WorkerRunState}> */
    public static function otherEnds(): iterable
    {
        yield 'failed' => ['failed', WorkerRunState::Failed];
        yield 'stopped' => ['stopped', WorkerRunState::Stopped];
        yield 'not-started' => ['not-started', WorkerRunState::NotStarted];
        yield 'gave-up' => ['gave-up', WorkerRunState::GaveUp];
        yield 'usage-limit' => ['usage-limit', WorkerRunState::UsageLimit];
        yield 'network' => ['network', WorkerRunState::Network];
    }

    #[DataProvider('otherEnds')]
    public function test_any_other_end_keeps_its_state_and_leaves_the_open_wait_open(string $end, WorkerRunState $expected): void
    {
        $run = $this->runningRun();
        $wait = $this->wait($run);

        $this->end($end);

        self::assertSame($expected, $this->reload($run)->state);
        self::assertTrue($this->reloadWait($wait)->isOpen());
    }

    private function runningRun(): WorkerRun
    {
        $this->handle(WorkerRunState::Running);

        $run = $this->em()->createQueryBuilder()->select('r')->from(WorkerRun::class, 'r')->andWhere('r.runKey = :key')->setParameter('key', $this->runKey, UuidType::NAME)->getQuery()->getOneOrNullResult();
        self::assertNotNull($run);

        return $run;
    }

    private function wait(WorkerRun $run): WorkerWait
    {
        $opened = new \DateTimeImmutable('2026-09-23 10:02:00');
        $wait = new WorkerWait($this->project, $this->cardId, $run->id ?? throw new \LogicException('Seeded.'), $run->id, Uuid::fromString(self::BRIDGE), Uuid::fromString(self::SESSION), [], $opened, $opened->modify('+1 hour'));
        $this->em()->persist($wait);
        $this->em()->flush();

        return $wait;
    }

    private function end(string $end, ?string $output = null): void
    {
        $state = WorkerRunState::from($end);
        $this->handle($state, $output);
    }

    private function handle(WorkerRunState $state, ?string $output = null, ?Uuid $continues = null, ?Uuid $runKey = null): void
    {
        $handler = self::getContainer()->get(ReportWorkerRunStateHandler::class);
        self::assertInstanceOf(ReportWorkerRunStateHandler::class, $handler);
        $outcome = $state->isOutcome();
        $exit = match ($state) {
            WorkerRunState::Failed => 1,
            WorkerRunState::NotStarted, WorkerRunState::Stopped, WorkerRunState::Running => null,
            default => 0,
        };

        $handler(new ReportWorkerRunStateCommand(
            owner: $this->owner,
            handle: (string) $this->project->id,
            runKey: $runKey ?? $this->runKey,
            bridgeId: Uuid::fromString(self::BRIDGE),
            state: $state,
            at: new \DateTimeImmutable('2026-09-23 10:0'.$state->rank().':00'),
            subject: WorkSubject::card($this->cardId),
            cardNumber: 1,
            workKind: 'plan',
            sessionId: WorkerRunState::NotStarted === $state ? null : Uuid::fromString(self::SESSION),
            startedAt: WorkerRunState::NotStarted === $state ? null : new \DateTimeImmutable('2026-09-23 10:00:00'),
            endedAt: $outcome || WorkerRunState::Stopped === $state ? new \DateTimeImmutable('2026-09-23 10:05:00') : null,
            exitCode: $exit,
            hasResult: match ($state) {
                WorkerRunState::Failed, WorkerRunState::NoResult => false,
                WorkerRunState::Succeeded, WorkerRunState::Unfinished, WorkerRunState::Blocked, WorkerRunState::GaveUp => true,
                default => null,
            },
            failureReason: WorkerRunState::NotStarted === $state ? 'no claude' : null,
            output: $output ?? ($outcome ? 'output' : null),
            resultStatus: match ($state) {
                WorkerRunState::Succeeded => 'finished',
                WorkerRunState::Unfinished => 'unfinished',
                WorkerRunState::Blocked => 'blocked',
                default => null,
            },
            continues: $continues,
        ));
    }

    private function reload(WorkerRun $run): WorkerRun
    {
        $this->em()->clear();

        return $this->em()->find(WorkerRun::class, $run->id) ?? throw new \LogicException('The run exists.');
    }

    private function reloadWait(WorkerWait $wait): WorkerWait
    {
        $this->em()->clear();

        return $this->em()->find(WorkerWait::class, $wait->id) ?? throw new \LogicException('The wait exists.');
    }

    /** @return list<string> */
    private function history(WorkerRun $run): array
    {
        $this->em()->clear();
        $changes = $this->em()->createQueryBuilder()->select('c')->from(WorkerRunStateChange::class, 'c')->andWhere('c.run = :run')->setParameter('run', $run->id, UuidType::NAME)->orderBy('c.sequence', 'ASC')->getQuery()->getResult();

        return array_values(array_map(static fn (WorkerRunStateChange $change): string => $change->state->value, $changes));
    }
}
