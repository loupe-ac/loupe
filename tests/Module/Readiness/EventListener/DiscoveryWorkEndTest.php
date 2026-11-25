<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\EventListener;

use App\Module\Bridge\Command\WithdrawWorkRequestCommand;
use App\Module\Bridge\Command\WithdrawWorkRequestHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Bridge\WorkSubject\WorkSubjectHandlers;
use App\Module\Project\Entity\Project;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Tests\Module\Readiness\DiscoveryScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** How the end of the work of a discovery run, an expired or refused request or an ended worker, reaches the run. */
final class DiscoveryWorkEndTest extends KernelTestCase
{
    use DiscoveryScenario;

    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->project = $this->workflowProject('discovery-end');
    }

    public function test_the_subject_type_of_a_run_belongs_to_its_project_alone(): void
    {
        $run = $this->discoveryRun($this->project);
        $subjects = $this->subjects();
        $subject = new WorkSubject(DiscoveryRun::SUBJECT_TYPE, $run->id ?? throw new \LogicException('Stored.'));

        self::assertTrue($subjects->belongsTo($this->project, $subject));
        self::assertFalse($subjects->belongsTo($this->workflowProject('discovery-end-other'), $subject));
    }

    /** The request id is not stored yet, as inside the open. */
    public function test_an_expired_request_fails_the_run_with_its_reason(): void
    {
        $run = $this->discoveryRun($this->project);
        $request = $this->discoveryRequest($run);

        $this->withdraw($request, WorkRequestState::Expired, WorkRequest::REASON_NO_TAKER);

        $stored = $this->storedRun($run);
        self::assertSame([DiscoveryRunState::Failed, WorkRequest::REASON_NO_TAKER], [$stored->state, $stored->failureReason]);
        self::assertNotNull($stored->endedAt);
    }

    public function test_an_expired_request_with_no_reason_reads_as_no_taker(): void
    {
        $run = $this->discoveryRun($this->project);

        $this->withdraw($this->discoveryRequest($run), WorkRequestState::Expired);

        self::assertSame(WorkRequest::REASON_NO_TAKER, $this->storedRun($run)->failureReason);
    }

    /** @return iterable<string, array{?string, string}> */
    public static function refusals(): iterable
    {
        yield 'with a reason' => ['no such skill', 'no such skill'];
        yield 'with none' => [null, 'refused'];
    }

    #[DataProvider('refusals')]
    public function test_a_refused_request_fails_the_run(?string $reason, string $expected): void
    {
        $run = $this->discoveryRun($this->project);
        $request = $this->discoveryRequest($run);
        $request->state = WorkRequestState::Refused;
        $request->reason = $reason;

        $this->subjects()->for(DiscoveryRun::SUBJECT_TYPE)?->onSettled($request);

        self::assertSame([DiscoveryRunState::Failed, $expected], [$run->state, $run->failureReason]);
    }

    public function test_a_done_request_leaves_the_run_requested(): void
    {
        $run = $this->discoveryRun($this->project);
        $request = $this->discoveryRequest($run);
        $request->state = WorkRequestState::Done;

        $this->subjects()->for(DiscoveryRun::SUBJECT_TYPE)?->onSettled($request);

        self::assertSame(DiscoveryRunState::Requested, $run->state);
    }

    /** Both kinds share the subject, so a late discovery request must not end a revision. */
    public function test_an_expired_discovery_request_leaves_a_revising_run_alone(): void
    {
        $run = $this->discoveryRun($this->project, DiscoveryRunState::Revising);

        $this->withdraw($this->discoveryRequest($run), WorkRequestState::Expired);

        self::assertSame(DiscoveryRunState::Revising, $this->storedRun($run)->state);
    }

    public function test_an_expired_revise_request_fails_a_revising_run(): void
    {
        $run = $this->discoveryRun($this->project, DiscoveryRunState::Revising);

        $this->withdraw($this->discoveryRequest($run, 'discovery-revise'), WorkRequestState::Expired);

        self::assertSame(DiscoveryRunState::Failed, $this->storedRun($run)->state);
    }

    public function test_an_expired_card_request_of_the_discovery_rule_fails_no_run(): void
    {
        $run = $this->discoveryRun($this->project);
        $card = $this->card($this->project);
        // The same id as the run, so only the subject type tells them apart.
        $request = new WorkRequest($this->project, WorkSubject::CARD, $run->id ?? throw new \LogicException('Stored.'), $card->number, 'discovery', null, 'discovery', new \DateTimeImmutable());
        $this->em()->persist($request);
        $this->em()->flush();

        $this->withdraw($request, WorkRequestState::Expired);

        self::assertSame(DiscoveryRunState::Requested, $this->storedRun($run)->state);
    }

    /** @return iterable<string, array{WorkerRunState, ?string, string}> */
    public static function endedRuns(): iterable
    {
        yield 'stopped by a person' => [WorkerRunState::Stopped, null, 'worker-ended'];
        yield 'timed out' => [WorkerRunState::TimedOut, null, 'worker-ended'];
        yield 'lost' => [WorkerRunState::Lost, null, 'worker-ended'];
        yield 'succeeded with no report' => [WorkerRunState::Succeeded, null, 'worker-ended'];
        yield 'not started, with a reason' => [WorkerRunState::NotStarted, 'claude: command not found', 'claude: command not found'];
    }

    #[DataProvider('endedRuns')]
    public function test_a_discovery_worker_that_ends_fails_the_run_and_cancels_its_request(WorkerRunState $state, ?string $failureReason, string $expected): void
    {
        $run = $this->discoveryRun($this->project);
        $request = $this->discoveryRequest($run);
        $request->state = WorkRequestState::Claimed;
        $this->em()->flush();

        $this->end($this->workerRun($run, $request, $state, $failureReason));

        self::assertSame([DiscoveryRunState::Failed, $expected], [$run->state, $run->failureReason]);
        self::assertSame(WorkRequestState::Cancelled, $request->state);
    }

    public function test_a_revise_worker_that_ends_fails_a_revising_run(): void
    {
        $run = $this->discoveryRun($this->project, DiscoveryRunState::Revising);
        $request = $this->discoveryRequest($run, 'discovery-revise');

        $this->end($this->workerRun($run, $request, WorkerRunState::Lost));

        self::assertSame([DiscoveryRunState::Failed, 'worker-ended'], [$run->state, $run->failureReason]);
        self::assertSame(WorkRequestState::Cancelled, $request->state);
    }

    public function test_a_late_discovery_worker_leaves_a_revising_run_alone(): void
    {
        $run = $this->discoveryRun($this->project, DiscoveryRunState::Revising);

        $this->end($this->workerRun($run, $this->discoveryRequest($run), WorkerRunState::Lost));

        self::assertSame(DiscoveryRunState::Revising, $run->state);
    }

    /** The app cancelled the request itself, such as the one of an earlier revision, so the end of its worker says nothing about the run. */
    public function test_a_worker_of_a_cancelled_request_leaves_the_run_alone(): void
    {
        $run = $this->discoveryRun($this->project, DiscoveryRunState::Revising);
        $request = $this->discoveryRequest($run, 'discovery-revise');
        $request->withdraw(WorkRequestState::Cancelled, new \DateTimeImmutable());
        $this->em()->flush();

        $this->end($this->workerRun($run, $request, WorkerRunState::Stopped));

        self::assertSame(DiscoveryRunState::Revising, $run->state);
    }

    /** @return iterable<string, array{WorkerRunState}> */
    public static function continuingRuns(): iterable
    {
        yield 'running' => [WorkerRunState::Running];
        yield 'resumed' => [WorkerRunState::Resumed];
        yield 'replaced' => [WorkerRunState::Replaced];
        yield 'skipped' => [WorkerRunState::Skipped];
        yield 'dropped' => [WorkerRunState::Dropped];
    }

    #[DataProvider('continuingRuns')]
    public function test_a_discovery_worker_whose_work_goes_on_leaves_the_run_requested(WorkerRunState $state): void
    {
        $run = $this->discoveryRun($this->project);
        $request = $this->discoveryRequest($run);

        $this->end($this->workerRun($run, $request, $state));

        self::assertSame(DiscoveryRunState::Requested, $run->state);
        self::assertSame(WorkRequestState::Open, $request->state);
    }

    public function test_a_card_worker_of_the_discovery_rule_fails_no_run(): void
    {
        $run = $this->discoveryRun($this->project);
        $card = $this->card($this->project);
        // The same id as the run, so only the subject type tells them apart.
        $worker = new WorkerRun(
            project: $this->project,
            bridgeId: Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: $run->id ?? throw new \LogicException('Stored.'),
            cardNumber: $card->number,
            workKind: 'discovery',
            state: WorkerRunState::Lost,
            ruleId: 'discovery',
        );
        $this->em()->persist($worker);
        $this->em()->flush();

        $this->end($worker);

        self::assertSame(DiscoveryRunState::Requested, $run->state);
    }

    public function test_a_run_that_already_reported_or_failed_is_not_touched(): void
    {
        $reported = $this->discoveryRun($this->project, DiscoveryRunState::Reported);
        $failed = $this->discoveryRun($this->project, DiscoveryRunState::Failed);
        $failed->failureReason = 'no-taker';
        $this->em()->flush();

        $this->end($this->workerRun($reported, $this->discoveryRequest($reported), WorkerRunState::Succeeded));
        $this->end($this->workerRun($failed, $this->discoveryRequest($failed), WorkerRunState::Lost));

        self::assertSame([DiscoveryRunState::Reported, null], [$reported->state, $reported->failureReason]);
        self::assertSame([DiscoveryRunState::Failed, 'no-taker'], [$failed->state, $failed->failureReason]);
    }

    private function subjects(): WorkSubjectHandlers
    {
        $subjects = self::getContainer()->get(WorkSubjectHandlers::class);
        self::assertInstanceOf(WorkSubjectHandlers::class, $subjects);

        return $subjects;
    }

    private function withdraw(WorkRequest $request, WorkRequestState $state, ?string $reason = null): void
    {
        $withdraw = self::getContainer()->get(WithdrawWorkRequestHandler::class);
        self::assertInstanceOf(WithdrawWorkRequestHandler::class, $withdraw);

        self::assertTrue($withdraw(new WithdrawWorkRequestCommand($request->id ?? throw new \LogicException('Stored.'), $state, reason: $reason)));
    }

    private function workerRun(DiscoveryRun $run, WorkRequest $request, WorkerRunState $state, ?string $failureReason = null): WorkerRun
    {
        $worker = new WorkerRun(
            project: $this->project,
            bridgeId: Uuid::v7(),
            subjectType: DiscoveryRun::SUBJECT_TYPE,
            subjectId: $run->id ?? throw new \LogicException('Stored.'),
            cardNumber: null,
            workKind: $request->kind,
            state: $state,
            failureReason: $failureReason,
            workRequestId: $request->id,
            ruleId: $request->ruleId,
        );
        $this->em()->persist($worker);
        $this->em()->flush();

        return $worker;
    }

    private function end(WorkerRun ...$workers): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        foreach (WorkerRunChanged::ofRuns($workers) as $event) {
            $dispatcher->dispatch($event);
        }
    }
}
