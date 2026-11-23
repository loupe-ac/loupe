<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Entity;

use App\Module\Bridge\Entity\WorkerWait;
use App\Module\Bridge\ValueObject\WorkerWaitEndReason;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WorkerWaitTest extends TestCase
{
    private const string OPENED = '2026-09-23 10:00:00';

    public function test_a_new_wait_is_open_and_awaits_its_resume(): void
    {
        $wait = $this->wait();

        self::assertTrue($wait->isOpen());
        self::assertTrue($wait->awaitsResume());
    }

    public function test_ending_a_wait_records_the_reason_the_time_and_the_met_conditions(): void
    {
        $wait = $this->wait();
        $met = [['key' => 'pr.checks_passed', 'params' => [], 'pullRequestId' => null, 'token' => 'pr:a/b#1']];
        $at = new \DateTimeImmutable('2026-09-23 10:20:00');

        self::assertTrue($wait->end(WorkerWaitEndReason::Condition, $at, $met));

        self::assertFalse($wait->isOpen());
        self::assertSame($at, $wait->endedAt);
        self::assertSame(WorkerWaitEndReason::Condition, $wait->endReason);
        self::assertSame($met, $wait->metConditions);
    }

    public function test_a_wait_that_ended_keeps_its_first_end(): void
    {
        $wait = $this->wait();
        $wait->end(WorkerWaitEndReason::TimedOut, new \DateTimeImmutable('2026-09-23 11:00:00'));

        self::assertFalse($wait->end(WorkerWaitEndReason::RunFinished, new \DateTimeImmutable('2026-09-23 11:05:00')));

        self::assertSame(WorkerWaitEndReason::TimedOut, $wait->endReason);
        self::assertEquals(new \DateTimeImmutable('2026-09-23 11:00:00'), $wait->endedAt);
    }

    /** @return iterable<string, array{WorkerWaitEndReason, bool}> */
    public static function endReasons(): iterable
    {
        yield 'condition' => [WorkerWaitEndReason::Condition, true];
        yield 'timed-out' => [WorkerWaitEndReason::TimedOut, true];
        yield 'subject-gone' => [WorkerWaitEndReason::SubjectGone, true];
        yield 'resumed-by-person' => [WorkerWaitEndReason::ResumedByPerson, true];
        yield 'cancelled-by-person' => [WorkerWaitEndReason::CancelledByPerson, false];
        yield 'card-moved' => [WorkerWaitEndReason::CardMoved, false];
        yield 'card-finished' => [WorkerWaitEndReason::CardFinished, false];
        yield 'card-held' => [WorkerWaitEndReason::CardHeld, false];
        yield 'run-finished' => [WorkerWaitEndReason::RunFinished, false];
    }

    #[DataProvider('endReasons')]
    public function test_only_some_ends_await_a_resume(WorkerWaitEndReason $reason, bool $awaits): void
    {
        $wait = $this->wait();
        $wait->end($reason, new \DateTimeImmutable('2026-09-23 10:30:00'));

        self::assertSame($awaits, $wait->awaitsResume());
        self::assertSame($awaits, $reason->resumesSession());
    }

    public function test_a_wait_that_a_run_resumed_awaits_nothing(): void
    {
        $wait = $this->wait();
        $wait->end(WorkerWaitEndReason::Condition, new \DateTimeImmutable('2026-09-23 10:30:00'));
        $wait->resumeRunId = Uuid::v7();

        self::assertFalse($wait->awaitsResume());
    }

    private function wait(): WorkerWait
    {
        $opened = new \DateTimeImmutable(self::OPENED);

        return new WorkerWait(
            project: $this->createStub(Project::class),
            cardId: Uuid::v7(),
            runId: Uuid::v7(),
            chainRootRunId: Uuid::v7(),
            bridgeId: Uuid::v7(),
            sessionId: Uuid::v4(),
            conditions: [['key' => 'pr.checks_passed', 'params' => [], 'pullRequestId' => null, 'token' => null]],
            openedAt: $opened,
            limitAt: $opened->modify('+1 hour'),
        );
    }
}
