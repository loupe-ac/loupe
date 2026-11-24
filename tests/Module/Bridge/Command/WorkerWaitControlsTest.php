<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Bridge\Command\CancelWaitCommand;
use App\Module\Bridge\Command\CancelWaitHandler;
use App\Module\Bridge\Command\ChangeWaitLimitCommand;
use App\Module\Bridge\Command\ChangeWaitLimitHandler;
use App\Module\Bridge\Command\EndWaitsOfCardCommand;
use App\Module\Bridge\Command\EndWaitsOfCardHandler;
use App\Module\Bridge\Command\ResumeWaitNowCommand;
use App\Module\Bridge\Command\ResumeWaitNowHandler;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerWait;
use App\Module\Bridge\Messenger\ResumeWaitingSession;
use App\Module\Bridge\Messenger\StopStrandedWaitOfCard;
use App\Module\Bridge\Messenger\StopStrandedWaitOfCardHandler;
use App\Module\Bridge\Repository\WorkerWaitRepository;
use App\Module\Bridge\Service\WorkerWaitEnder;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerWaitEndReason;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/** The owner ends a wait, resumes it early or moves its time limit, and a move, a finish or a hold of the card ends it. */
final class WorkerWaitControlsTest extends KernelTestCase
{
    use BridgeScenario;

    private Project $project;
    private Uuid $cardId;

    public function test_resume_now_ends_the_wait_and_asks_for_the_resume_of_a_parked_run(): void
    {
        [$wait, $run] = $this->parkedWait(WorkerRunState::Waiting);

        ($this->resumeNow())(new ResumeWaitNowCommand($this->project, $this->cardId));

        $stored = $this->reload($wait);
        self::assertSame(WorkerWaitEndReason::ResumedByPerson, $stored->endReason);
        self::assertSame(WorkerRunState::Waiting, $this->reloadRun($run)->state);
        self::assertSame([(string) $wait->id], array_map(static fn (ResumeWaitingSession $message): string => $message->waitId, $this->resumeMessages()));
    }

    public function test_resume_now_on_a_live_run_leaves_the_resume_to_the_end_of_the_run(): void
    {
        [$wait] = $this->parkedWait(WorkerRunState::Running);

        ($this->resumeNow())(new ResumeWaitNowCommand($this->project, $this->cardId));

        self::assertSame(WorkerWaitEndReason::ResumedByPerson, $this->reload($wait)->endReason);
        self::assertSame([], $this->resumeMessages());
    }

    public function test_cancel_ends_the_wait_and_stops_the_parked_run_with_no_resume(): void
    {
        [$wait, $run] = $this->parkedWait(WorkerRunState::Waiting);

        (self::getContainer()->get(CancelWaitHandler::class))(new CancelWaitCommand($this->project, $this->cardId));

        self::assertSame(WorkerWaitEndReason::CancelledByPerson, $this->reload($wait)->endReason);
        self::assertSame(WorkerRunState::Stopped, $this->reloadRun($run)->state);
        self::assertSame([], $this->resumeMessages());
    }

    public function test_a_cancel_writes_a_wait_ended_row_to_the_card_history(): void
    {
        $this->parkedWait(WorkerRunState::Waiting);

        (self::getContainer()->get(CancelWaitHandler::class))(new CancelWaitCommand($this->project, $this->cardId));

        $this->em()->clear();
        $rows = self::getContainer()->get(\App\Module\Board\Repository\CardEventRepository::class)->findBy(['card' => $this->cardId, 'kind' => CardEventKind::WaitEnded]);
        self::assertCount(1, $rows);
        self::assertSame('cancelled-by-person', $rows[0]->detail['reason']);
    }

    /** @return iterable<string, array{WorkerWaitEndReason}> */
    public static function cardChanges(): iterable
    {
        yield 'moved' => [WorkerWaitEndReason::CardMoved];
        yield 'finished' => [WorkerWaitEndReason::CardFinished];
        yield 'held' => [WorkerWaitEndReason::CardHeld];
    }

    #[DataProvider('cardChanges')]
    public function test_a_change_of_the_card_ends_the_wait_and_stops_the_parked_run(WorkerWaitEndReason $reason): void
    {
        [$wait, $run] = $this->parkedWait(WorkerRunState::Waiting);

        (self::getContainer()->get(EndWaitsOfCardHandler::class))(new EndWaitsOfCardCommand($this->cardId, $reason));

        self::assertSame($reason, $this->reload($wait)->endReason);
        self::assertSame(WorkerRunState::Stopped, $this->reloadRun($run)->state);
        self::assertSame([], $this->resumeMessages());
    }

    public function test_a_change_of_a_card_with_no_wait_does_nothing(): void
    {
        self::bootKernel();
        $this->project = $this->project($this->em(), $this->user($this->em(), 'no-wait-'.uniqid().'@example.com'), 'No wait');
        $cardId = $this->seedCard($this->em(), $this->project)->id ?? throw new \LogicException('Seeded.');

        (self::getContainer()->get(EndWaitsOfCardHandler::class))(new EndWaitsOfCardCommand($cardId, WorkerWaitEndReason::CardMoved));

        self::assertNull(self::getContainer()->get(WorkerWaitRepository::class)->findOpenOfCard($cardId));
    }

    public function test_an_end_that_loses_the_race_changes_nothing(): void
    {
        [$wait, $run] = $this->parkedWait(WorkerRunState::Waiting);
        // Another request ended the wait after this one read it as open.
        $this->em()->getConnection()->executeStatement(
            "UPDATE bridge_worker_waits SET ended_at = now(), end_reason = 'resumed-by-person' WHERE id = :id",
            ['id' => (string) $wait->id],
        );

        $ended = self::getContainer()->get(WorkerWaitEnder::class)->end($wait, WorkerWaitEndReason::CancelledByPerson);

        self::assertFalse($ended);
        self::assertSame(WorkerWaitEndReason::ResumedByPerson, $this->reload($wait)->endReason);
        self::assertSame(WorkerRunState::Waiting, $this->reloadRun($run)->state);
    }

    public function test_a_control_on_a_card_with_no_open_wait_is_refused(): void
    {
        [$wait] = $this->parkedWait(WorkerRunState::Succeeded);
        $wait->end(WorkerWaitEndReason::Condition, new \DateTimeImmutable());
        $this->em()->flush();

        $this->expectException(DomainErrors::class);
        ($this->resumeNow())(new ResumeWaitNowCommand($this->project, $this->cardId));
    }

    public function test_resume_now_on_a_stranded_wait_requests_the_resume_at_once_and_keeps_the_end_reason(): void
    {
        [$wait, $run] = $this->parkedWait(WorkerRunState::Waiting);
        $wait->end(WorkerWaitEndReason::Condition, new \DateTimeImmutable('-5 minutes'));
        $this->em()->flush();
        $this->em()->getConnection()->executeStatement('UPDATE bridges SET capabilities = CAST(:capabilities AS jsonb) WHERE id = :id', ['capabilities' => json_encode([Bridge::CAPABILITY_COMMANDS]), 'id' => (string) $wait->bridgeId]);
        $this->em()->clear();

        ($this->resumeNow())(new ResumeWaitNowCommand($this->project, $this->cardId));

        self::assertSame(WorkerWaitEndReason::Condition, $this->reload($wait)->endReason);
        self::assertSame(1, $this->countCommands($this->em()));
        self::assertSame(WorkerRunState::Waiting, $this->reloadRun($run)->state);
    }

    public function test_cancel_on_a_stranded_wait_stops_the_run_and_writes_the_history_row(): void
    {
        [$wait, $run] = $this->parkedWait(WorkerRunState::Waiting);
        $wait->end(WorkerWaitEndReason::Condition, new \DateTimeImmutable('-5 minutes'));
        $this->em()->flush();

        (self::getContainer()->get(CancelWaitHandler::class))(new CancelWaitCommand($this->project, $this->cardId));

        self::assertSame(WorkerRunState::Stopped, $this->reloadRun($run)->state);
        self::assertSame(WorkerWaitEndReason::Condition, $this->reload($wait)->endReason);
        self::assertNull(self::getContainer()->get(WorkerWaitRepository::class)->findStrandedOfCard($this->cardId));
        $rows = self::getContainer()->get(\App\Module\Board\Repository\CardEventRepository::class)->findBy(['card' => $this->cardId, 'kind' => CardEventKind::WaitEnded]);
        self::assertCount(1, $rows);
        self::assertSame('cancelled-by-person', $rows[0]->detail['reason']);
    }

    #[DataProvider('cardChanges')]
    public function test_a_change_of_the_card_stops_the_run_of_a_stranded_wait(WorkerWaitEndReason $reason): void
    {
        [$wait, $run] = $this->parkedWait(WorkerRunState::Waiting);
        $wait->end(WorkerWaitEndReason::TimedOut, new \DateTimeImmutable('-5 minutes'));
        $this->em()->flush();

        (self::getContainer()->get(EndWaitsOfCardHandler::class))(new EndWaitsOfCardCommand($this->cardId, $reason));

        self::assertSame(WorkerRunState::Waiting, $this->reloadRun($run)->state, 'The stop runs on the queue, outside the lock of the card change.');
        (self::getContainer()->get(StopStrandedWaitOfCardHandler::class))(new StopStrandedWaitOfCard((string) $wait->id, $reason));

        self::assertSame(WorkerRunState::Stopped, $this->reloadRun($run)->state);
        self::assertSame(WorkerWaitEndReason::TimedOut, $this->reload($wait)->endReason);
    }

    public function test_a_card_change_marks_the_stranded_wait_so_a_resume_request_refuses_it_before_the_stop_runs(): void
    {
        [$wait, $run] = $this->parkedWait(WorkerRunState::Waiting);
        $wait->end(WorkerWaitEndReason::TimedOut, new \DateTimeImmutable('-5 minutes'));
        $this->em()->flush();

        (self::getContainer()->get(EndWaitsOfCardHandler::class))(new EndWaitsOfCardCommand($this->cardId, WorkerWaitEndReason::CardMoved));
        $this->em()->clear();

        $repository = self::getContainer()->get(WorkerWaitRepository::class);
        self::assertTrue($repository->isStopRequestedOfRun($run->id ?? throw new \LogicException('Seeded.')));
        self::assertNull($repository->findStrandedOfCard($this->cardId), 'A marked wait is no longer offered for a resume.');
    }

    public function test_a_resume_request_is_refused_once_a_card_change_marked_the_wait(): void
    {
        [$wait, $run] = $this->parkedWait(WorkerRunState::Waiting);
        $wait->end(WorkerWaitEndReason::TimedOut, new \DateTimeImmutable('-5 minutes'));
        $this->em()->flush();
        $this->em()->getConnection()->executeStatement('UPDATE bridges SET capabilities = CAST(:capabilities AS jsonb) WHERE id = :id', ['capabilities' => json_encode([Bridge::CAPABILITY_COMMANDS]), 'id' => (string) $wait->bridgeId]);
        (self::getContainer()->get(EndWaitsOfCardHandler::class))(new EndWaitsOfCardCommand($this->cardId, WorkerWaitEndReason::CardMoved));
        $this->em()->clear();
        $run = $this->reloadRun($run);

        try {
            (self::getContainer()->get(\App\Module\Bridge\Command\RequestBridgeCommandHandler::class))(new \App\Module\Bridge\Command\RequestBridgeCommandCommand($run, \App\Module\Bridge\ValueObject\BridgeCommandKind::ResumeRun, null, cause: \App\Module\Bridge\ValueObject\BridgeCommandCause::WaitEnded));
            self::fail('The resume request must be refused.');
        } catch (DomainErrors $e) {
            self::assertSame(['run' => \App\Module\Bridge\Command\RequestBridgeCommandHandler::NOT_RESUMABLE], $e->errors);
        }
        self::assertSame(0, $this->countCommands($this->em()));
    }

    public function test_a_stop_for_a_wait_with_no_mark_changes_nothing(): void
    {
        [$wait, $run] = $this->parkedWait(WorkerRunState::Waiting);
        $wait->end(WorkerWaitEndReason::TimedOut, new \DateTimeImmutable('-5 minutes'));
        $this->em()->flush();

        (self::getContainer()->get(StopStrandedWaitOfCardHandler::class))(new StopStrandedWaitOfCard((string) $wait->id, WorkerWaitEndReason::CardMoved));

        self::assertSame(WorkerRunState::Waiting, $this->reloadRun($run)->state);
    }

    public function test_a_stranded_wait_of_a_live_run_is_not_stranded(): void
    {
        [$wait] = $this->parkedWait(WorkerRunState::Running);
        $wait->end(WorkerWaitEndReason::Condition, new \DateTimeImmutable('-5 minutes'));
        $this->em()->flush();

        self::assertNull(self::getContainer()->get(WorkerWaitRepository::class)->findStrandedOfCard($this->cardId));
    }

    public function test_a_wait_of_another_project_is_out_of_reach(): void
    {
        [$wait] = $this->parkedWait(WorkerRunState::Waiting);
        $other = $this->project($this->em(), $this->user($this->em(), 'other-'.uniqid().'@example.com'), 'Other');

        try {
            (self::getContainer()->get(CancelWaitHandler::class))(new CancelWaitCommand($other, $this->cardId));
            self::fail('The cancel must be refused.');
        } catch (DomainErrors $e) {
            self::assertSame(['wait' => 'bridge.wait.error.no_open_wait'], $e->errors);
        }
        self::assertTrue($this->reload($wait)->isOpen());
    }

    public function test_the_time_limit_moves_within_the_longest_wait(): void
    {
        [$wait] = $this->parkedWait(WorkerRunState::Waiting);

        (self::getContainer()->get(ChangeWaitLimitHandler::class))(new ChangeWaitLimitCommand($this->project, $this->cardId, 90));

        $stored = $this->reload($wait);
        self::assertTrue($stored->isOpen());
        self::assertEquals($stored->openedAt->modify('+90 minutes'), $stored->limitAt);
    }

    /** @return iterable<string, array{int, string}> */
    public static function refusedLimits(): iterable
    {
        yield 'over the longest wait' => [100_000, 'bridge.wait.error.limit_over_maximum'];
        yield 'already passed' => [1, 'bridge.wait.error.limit_passed'];
    }

    #[DataProvider('refusedLimits')]
    public function test_a_limit_out_of_range_is_refused_and_changes_nothing(int $minutes, string $key): void
    {
        [$wait] = $this->parkedWait(WorkerRunState::Waiting, openedAt: new \DateTimeImmutable('-3 hours'));
        $before = $wait->limitAt;

        try {
            (self::getContainer()->get(ChangeWaitLimitHandler::class))(new ChangeWaitLimitCommand($this->project, $this->cardId, $minutes));
            self::fail('The change must be refused.');
        } catch (DomainErrors $e) {
            self::assertSame(['minutes' => $key], $e->errors);
        }
        self::assertSame($before->getTimestamp(), $this->reload($wait)->limitAt->getTimestamp());
    }

    /** @return array{WorkerWait, WorkerRun} */
    private function parkedWait(WorkerRunState $runState, ?\DateTimeImmutable $openedAt = null): array
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'wait-controls-'.uniqid().'@example.com');
        $this->project = $this->project($em, $owner, 'Wait controls');
        $this->cardId = $this->seedCard($em, $this->project)->id ?? throw new \LogicException('Seeded.');
        $bridge = $this->seedBridge($em, $owner);
        $run = $this->seedRun($em, $this->project, bridgeId: $bridge->id, cardId: $this->cardId, state: $runState);
        $opened = $openedAt ?? new \DateTimeImmutable('-10 minutes');
        $wait = new WorkerWait($this->project, $this->cardId, $run->id ?? throw new \LogicException('Seeded.'), $run->id, $bridge->id, Uuid::v4(), [], $opened, $opened->modify('+60 minutes'));
        $em->persist($wait);
        $em->flush();
        $this->transport()->reset();

        return [$wait, $run];
    }

    private function resumeNow(): ResumeWaitNowHandler
    {
        return self::getContainer()->get(ResumeWaitNowHandler::class);
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /** @return list<ResumeWaitingSession> */
    private function resumeMessages(): array
    {
        return array_values(array_filter(
            array_map(static fn ($envelope) => $envelope->getMessage(), $this->transport()->getSent()),
            static fn (object $message): bool => $message instanceof ResumeWaitingSession,
        ));
    }

    private function reload(WorkerWait $wait): WorkerWait
    {
        $this->em()->clear();

        return $this->em()->find(WorkerWait::class, $wait->id) ?? throw new \LogicException('The wait is stored.');
    }

    private function reloadRun(WorkerRun $run): WorkerRun
    {
        $this->em()->clear();

        return $this->em()->find(WorkerRun::class, $run->id) ?? throw new \LogicException('The run is stored.');
    }
}
