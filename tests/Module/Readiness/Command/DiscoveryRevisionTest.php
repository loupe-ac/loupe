<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Command;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\WorkSubject\WorkSubjectHandlers;
use App\Module\Project\Entity\Project;
use App\Module\Readiness\Command\ReportFinding;
use App\Module\Readiness\Command\StartDiscoveryHandler;
use App\Module\Readiness\Command\SubmitReadinessReportCommand;
use App\Module\Readiness\Command\SubmitReadinessReportHandler;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Event\DiscoveryReportWaiting;
use App\Module\Readiness\Messenger\RequestDiscoveryRevision;
use App\Module\Readiness\Messenger\RequestDiscoveryRevisionHandler;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Review;
use App\Tests\Module\Readiness\DiscoveryScenario;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/** The verdicts on a discovery report, and the work request that a request for changes opens after the verdict. */
final class DiscoveryRevisionTest extends KernelTestCase
{
    use DiscoveryScenario;

    private Project $project;

    private DiscoveryRun $run;

    private Bridge $bridge;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->project = $this->workflowProject('discovery-revision');
        $this->run = $this->discoveryRun($this->project);
        $this->bridge = $this->discoveryBridge($this->project);
        $this->jobs()->reset();
    }

    /** With no routing line the message runs inside the verdict transaction, and a bridge hears of the work before the verdict is saved. */
    public function test_a_request_for_changes_queues_the_revise_message_on_jobs_and_opens_no_request(): void
    {
        $report = $this->report();
        $previous = $this->discoveryRequest($this->run);
        $this->run->workRequestId = $previous->id;
        $this->em()->flush();
        $this->jobs()->reset();

        $this->verdict($report, 'changes-requested');

        self::assertSame(DiscoveryRunState::Revising, $this->run->state);
        self::assertEquals([new RequestDiscoveryRevision((string) $this->run->id, (string) $previous->id)], $this->revisionMessages());
        self::assertSame([$previous], $this->discoveryRequests($this->run));
    }

    public function test_the_message_opens_the_revise_request_and_stores_its_id(): void
    {
        $this->verdict($this->report(), 'changes-requested');

        $this->handle($this->revisionMessage());

        $requests = $this->discoveryRequests($this->run);
        self::assertCount(1, $requests);
        $request = $requests[0];
        self::assertSame(
            ['discovery-revise', 'discovery-revise', 'subject-discovery', null, true, WorkRequestState::Open],
            [$request->kind, $request->ruleId, $request->capability, $request->cardNumber, $request->failWhenNoTaker, $request->state],
        );
        self::assertSame(file_get_contents(\dirname(__DIR__, 4).'/config/workflows/app/prompts/discovery-revise.md'), $request->prompt);
        self::assertTrue($this->storedRun($this->run)->workRequestId?->equals($request->id));
    }

    public function test_a_second_delivery_keeps_the_request_that_the_first_one_opened(): void
    {
        $this->verdict($this->report(), 'changes-requested');
        $message = $this->revisionMessage();
        $this->handle($message);

        $this->handle($message);

        $requests = $this->discoveryRequests($this->run);
        self::assertCount(1, $requests);
        self::assertSame(WorkRequestState::Open, $requests[0]->state);
        self::assertTrue($this->storedRun($this->run)->workRequestId?->equals($requests[0]->id));
    }

    /** The owner can ask for changes again before the bridge settled the request of the last revision. */
    public function test_a_live_request_of_the_revision_before_makes_room_for_a_new_one(): void
    {
        $report = $this->report();
        $old = $this->discoveryRequest($this->run, 'discovery-revise');
        $old->state = WorkRequestState::Claimed;
        $this->run->workRequestId = $old->id;
        $this->em()->flush();
        $this->jobs()->reset();
        $this->verdict($report, 'changes-requested');

        $this->handle($this->revisionMessage());

        $requests = $this->discoveryRequests($this->run);
        self::assertCount(2, $requests);
        $states = [];
        foreach ($requests as $request) {
            $states[$request->id?->equals($old->id) ? 'old' : 'new'] = $request->state;
        }
        self::assertSame(['old' => WorkRequestState::Cancelled, 'new' => WorkRequestState::Open], $states);
        $stored = $this->storedRun($this->run);
        self::assertSame(DiscoveryRunState::Revising, $stored->state);
        self::assertFalse($stored->workRequestId?->equals($old->id));
    }

    public function test_a_revision_with_no_bridge_left_fails_the_run_and_keeps_the_request_id(): void
    {
        $this->verdict($this->report(), 'changes-requested');
        $this->bridge->projects = [];
        $this->em()->flush();

        $this->handle($this->revisionMessage());

        $stored = $this->storedRun($this->run);
        self::assertSame([DiscoveryRunState::Failed, WorkRequest::REASON_NO_TAKER], [$stored->state, $stored->failureReason]);
        $requests = $this->discoveryRequests($stored);
        self::assertCount(1, $requests);
        self::assertTrue($stored->workRequestId?->equals($requests[0]->id));
    }

    public function test_a_run_that_stopped_revising_before_the_message_asks_for_no_work(): void
    {
        $report = $this->report();
        $review = $this->verdict($report, 'changes-requested');
        $message = $this->revisionMessage();
        $this->verdict($report, 'approved', $review);

        $this->handle($message);

        self::assertSame([], $this->discoveryRequests($this->run));
    }

    /** The approval read the run before the request id was stored, so it cancelled nothing. */
    public function test_an_approval_that_lands_while_the_request_opens_gets_the_request_cancelled(): void
    {
        $this->verdict($this->report(), 'changes-requested');
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(WorkRequestChanged::class, function (WorkRequestChanged $event): void {
            if (WorkRequestState::Open === $event->state) {
                $this->em()->getConnection()->executeStatement("UPDATE discovery_runs SET state = 'done' WHERE id = ?", [(string) $this->run->id]);
            }
        });

        $this->handle($this->revisionMessage());

        $requests = $this->discoveryRequests($this->run);
        self::assertCount(1, $requests);
        self::assertSame(WorkRequestState::Cancelled, $requests[0]->state);
        self::assertSame(DiscoveryRunState::Done, $this->storedRun($this->run)->state);
    }

    /** The message stored the id after the verdict request loaded the run, so the approval reads the row. */
    public function test_an_approval_during_a_revision_cancels_the_request_the_row_names(): void
    {
        $report = $this->report();
        $review = $this->verdict($report, 'changes-requested');
        $request = $this->discoveryRequest($this->run, 'discovery-revise');
        $this->em()->getConnection()->executeStatement('UPDATE discovery_runs SET work_request_id = ? WHERE id = ?', [(string) $request->id, (string) $this->run->id]);
        self::assertNull($this->run->workRequestId);

        $this->verdict($report, 'approved', $review);

        self::assertSame(WorkRequestState::Cancelled, $request->state);
        self::assertSame(DiscoveryRunState::Done, $this->run->state);
    }

    /** A cancel makes the bridge stop the worker, and the worker that reported is still at work. */
    public function test_a_revised_report_leaves_its_revise_request_live_and_its_worker_end_fails_nothing(): void
    {
        $report = $this->report();
        $this->verdict($report, 'changes-requested');
        $this->handle($this->revisionMessage());
        $request = $this->discoveryRequests($this->run)[0];
        $request->state = WorkRequestState::Claimed;
        $this->em()->flush();

        $this->report();

        self::assertSame(WorkRequestState::Claimed, $request->state);

        $this->end($this->workerRun($request, WorkerRunState::Succeeded));

        self::assertSame(DiscoveryRunState::Reported, $this->storedRun($this->run)->state);
    }

    /** @return iterable<string, array{string}> */
    public static function ends(): iterable
    {
        yield 'the worker ended' => ['worker'];
        yield 'the request was refused' => ['refused'];
        yield 'the request expired' => ['expired'];
    }

    /** The bridge settles a request only after its worker ends, so the old work can end while the next revision waits for its message. */
    #[DataProvider('ends')]
    public function test_the_end_of_an_answered_request_cannot_fail_the_next_revision(string $end): void
    {
        $report = $this->report();
        $this->verdict($report, 'changes-requested');
        $this->handle($this->revisionMessage());
        $old = $this->discoveryRequests($this->run)[0];
        $old->state = WorkRequestState::Claimed;
        $this->em()->flush();
        $this->report();
        $this->verdict($report, 'changes-requested');

        $this->endWork($old, $end);

        self::assertSame(DiscoveryRunState::Revising, $this->storedRun($this->run)->state);
    }

    /** The message stores the id after it opens the request, so a quick report can come first. */
    #[DataProvider('ends')]
    public function test_the_end_of_an_answered_request_whose_id_the_run_never_stored_fails_nothing(string $end): void
    {
        $report = $this->report();
        $this->verdict($report, 'changes-requested');
        $old = $this->discoveryRequest($this->run, 'discovery-revise');
        $old->state = WorkRequestState::Claimed;
        $this->em()->flush();
        $this->report();
        $this->verdict($report, 'changes-requested');

        $this->endWork($old, $end);

        self::assertSame(DiscoveryRunState::Revising, $this->storedRun($this->run)->state);
    }

    /** A bridge keeps a final report it cannot deliver, so a worker can end two revisions late. */
    public function test_the_end_of_a_request_answered_two_revisions_ago_fails_nothing(): void
    {
        $report = $this->report();
        $this->verdict($report, 'changes-requested');
        $first = $this->discoveryRequest($this->run, 'discovery-revise');
        $this->report();
        $first->state = WorkRequestState::Done;
        $this->em()->flush();
        $this->verdict($report, 'changes-requested');
        $this->discoveryRequest($this->run, 'discovery-revise');
        $this->report();
        $this->verdict($report, 'changes-requested');

        $this->endWork($first, 'worker');

        self::assertSame(DiscoveryRunState::Revising, $this->storedRun($this->run)->state);
    }

    public function test_the_end_of_an_unanswered_request_still_fails_a_run_that_has_an_answered_one(): void
    {
        $report = $this->report();
        $this->verdict($report, 'changes-requested');
        $first = $this->discoveryRequest($this->run, 'discovery-revise');
        $this->report();
        $first->state = WorkRequestState::Done;
        $this->em()->flush();
        $this->verdict($report, 'changes-requested');
        $second = $this->discoveryRequest($this->run, 'discovery-revise');

        $this->endWork($second, 'worker');

        self::assertSame(DiscoveryRunState::Failed, $this->storedRun($this->run)->state);
    }

    /** The run can hold an older id, so only the mark of the report tells that the live request serves no new revision. */
    public function test_a_new_revision_replaces_a_live_answered_request_whose_id_the_run_never_stored(): void
    {
        $report = $this->report();
        $this->verdict($report, 'changes-requested');
        $old = $this->discoveryRequest($this->run, 'discovery-revise');
        $old->state = WorkRequestState::Claimed;
        $this->em()->flush();
        $this->report();
        $this->jobs()->reset();
        $this->verdict($report, 'changes-requested');

        $this->handle($this->revisionMessage());

        $requests = $this->discoveryRequests($this->run);
        self::assertCount(2, $requests);
        $states = [];
        foreach ($requests as $request) {
            $states[$request->id?->equals($old->id) ? 'old' : 'new'] = $request->state;
        }
        self::assertSame(['old' => WorkRequestState::Cancelled, 'new' => WorkRequestState::Open], $states);
        self::assertFalse($this->storedRun($this->run)->workRequestId?->equals($old->id));
    }

    /** The report read the live requests before this one existed, so nothing answered it. */
    public function test_a_report_that_lands_before_the_request_opens_gets_the_request_cancelled(): void
    {
        $this->verdict($this->report(), 'changes-requested');
        $this->onOpen(function (): void {
            $this->em()->getConnection()->executeStatement("UPDATE discovery_runs SET state = 'reported' WHERE id = ?", [(string) $this->run->id]);
        });

        $this->handle($this->revisionMessage());

        self::assertSame(WorkRequestState::Cancelled, $this->discoveryRequests($this->run)[0]->state);
    }

    /** The report answered the request, and its worker can still be at work. */
    public function test_a_report_that_answers_the_request_while_it_opens_leaves_it_live(): void
    {
        $this->verdict($this->report(), 'changes-requested');
        $this->onOpen(function (): void {
            $this->report();
        });

        $this->handle($this->revisionMessage());

        self::assertSame(WorkRequestState::Open, $this->discoveryRequests($this->run)[0]->state);
        self::assertSame(DiscoveryRunState::Reported, $this->storedRun($this->run)->state);
    }

    private function onOpen(\Closure $then): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(WorkRequestChanged::class, static function (WorkRequestChanged $event) use ($then): void {
            if (WorkRequestState::Open === $event->state) {
                $then();
            }
        });
    }

    private function endWork(WorkRequest $request, string $end): void
    {
        if ('worker' === $end) {
            $this->end($this->workerRun($request, WorkerRunState::Lost));

            return;
        }
        $handlers = self::getContainer()->get(WorkSubjectHandlers::class);
        self::assertInstanceOf(WorkSubjectHandlers::class, $handlers);
        $handler = $handlers->for(DiscoveryRun::SUBJECT_TYPE) ?? throw new \LogicException('Discovery has a handler.');
        if ('refused' === $end) {
            $request->state = WorkRequestState::Refused;
            $handler->onSettled($request);

            return;
        }
        $request->state = WorkRequestState::Expired;
        $handler->onExpired($request);
    }

    /** A cancel stops the worker, and the first worker still asks the owner its questions after the report. */
    public function test_a_first_report_leaves_the_discovery_request_live(): void
    {
        $request = $this->discoveryRequest($this->run);
        $request->state = WorkRequestState::Claimed;
        $this->run->workRequestId = $request->id;
        $this->em()->flush();

        $this->report();

        self::assertSame(WorkRequestState::Claimed, $request->state);
    }

    /** The process can die between the open and the store of the id, so the run can name an older request. */
    public function test_an_approval_during_a_revision_cancels_every_live_request_of_the_run(): void
    {
        $report = $this->report();
        $first = $this->discoveryRequest($this->run);
        $this->run->workRequestId = $first->id;
        $this->em()->flush();
        $review = $this->verdict($report, 'changes-requested');
        $newer = $this->discoveryRequest($this->run, 'discovery-revise');

        $this->verdict($report, 'approved', $review);

        self::assertSame([WorkRequestState::Cancelled, WorkRequestState::Cancelled], [$first->state, $newer->state]);
    }

    /** @return iterable<string, array{bool, DiscoveryRunState}> */
    public static function failedDeliveries(): iterable
    {
        yield 'with a retry left' => [true, DiscoveryRunState::Revising];
        yield 'with no retry left' => [false, DiscoveryRunState::Failed];
    }

    #[DataProvider('failedDeliveries')]
    public function test_a_revise_message_that_gives_up_fails_the_run(bool $willRetry, DiscoveryRunState $expected): void
    {
        $this->verdict($this->report(), 'changes-requested');

        $this->failDelivery($this->revisionMessage(), $willRetry);

        $stored = $this->storedRun($this->run);
        self::assertSame($expected, $stored->state);
        self::assertSame($willRetry ? null : StartDiscoveryHandler::REQUEST_FAILED, $stored->failureReason);
    }

    /** A failed transaction closes the entity manager, which is the usual way a delivery fails. */
    public function test_a_revise_message_that_gives_up_fails_the_run_after_the_entity_manager_closed(): void
    {
        $this->verdict($this->report(), 'changes-requested');
        $message = $this->revisionMessage();
        $this->em()->close();

        $this->failDelivery($message, false);

        self::assertSame('failed', $this->connection()->fetchOne('SELECT state FROM discovery_runs WHERE id = ?', [(string) $this->run->id]));
    }

    public function test_a_late_failure_of_a_revise_message_leaves_a_reported_run_alone(): void
    {
        $this->verdict($this->report(), 'changes-requested');
        $message = $this->revisionMessage();
        $this->report();

        $this->failDelivery($message, false);

        self::assertSame(DiscoveryRunState::Reported, $this->storedRun($this->run)->state);
    }

    public function test_each_report_announces_that_it_waits_after_its_commit(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $outside = $connection->getTransactionNestingLevel();
        $waiting = [];
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(DiscoveryReportWaiting::class, static function (DiscoveryReportWaiting $event) use (&$waiting, $connection): void {
            $waiting[] = [(string) $event->projectId, (string) $event->runId, (string) $event->documentId, $connection->getTransactionNestingLevel()];
        });

        $report = $this->report();
        $this->verdict($report, 'changes-requested');
        $this->report();

        $expected = [(string) $this->project->id, (string) $this->run->id, (string) $report->id, $outside];
        self::assertSame([$expected, $expected], $waiting);
    }

    private function failDelivery(RequestDiscoveryRevision $message, bool $willRetry): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        // The retry strategy of the transport reads the count and decides whether a retry is left.
        $envelope = new Envelope($message, $willRetry ? [] : [new RedeliveryStamp(100)]);
        $dispatcher->dispatch(new WorkerMessageFailedEvent($envelope, 'jobs', new \RuntimeException('The database went away.')));
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function workerRun(WorkRequest $request, WorkerRunState $state): WorkerRun
    {
        $worker = new WorkerRun(
            project: $this->project,
            bridgeId: Uuid::v7(),
            subjectType: DiscoveryRun::SUBJECT_TYPE,
            subjectId: $this->run->id ?? throw new \LogicException('Stored.'),
            cardNumber: null,
            workKind: $request->kind,
            state: $state,
            workRequestId: $request->id,
            ruleId: $request->ruleId,
        );
        $this->em()->persist($worker);
        $this->em()->flush();

        return $worker;
    }

    private function end(WorkerRun $worker): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        foreach (WorkerRunChanged::ofRuns([$worker]) as $event) {
            $dispatcher->dispatch($event);
        }
    }

    private function report(): Document
    {
        $submit = self::getContainer()->get(SubmitReadinessReportHandler::class);
        self::assertInstanceOf(SubmitReadinessReportHandler::class, $submit);

        return $submit(new SubmitReadinessReportCommand($this->project, (string) $this->run->id, 'lifecycle', [new ReportFinding('Tests run', 'ready', 'Yes.')]));
    }

    private function verdict(Document $report, string $verdict, ?Review $previous = null): Review
    {
        $submit = self::getContainer()->get(SubmitReviewHandler::class);
        self::assertInstanceOf(SubmitReviewHandler::class, $submit);

        return $submit(new SubmitReviewCommand($this->project->owner, $report, $verdict, $report->currentVersion()->versionNumber, 'A note.', $previous?->id?->toRfc4122()));
    }

    private function handle(RequestDiscoveryRevision $message): void
    {
        $handler = self::getContainer()->get(RequestDiscoveryRevisionHandler::class);
        self::assertInstanceOf(RequestDiscoveryRevisionHandler::class, $handler);
        $handler($message);
    }

    /** @return list<RequestDiscoveryRevision> */
    private function revisionMessages(): array
    {
        $messages = [];
        foreach ($this->jobs()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof RequestDiscoveryRevision) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    private function revisionMessage(): RequestDiscoveryRevision
    {
        $messages = $this->revisionMessages();
        self::assertCount(1, $messages);

        return $messages[0];
    }

    private function jobs(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
