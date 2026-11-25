<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Command;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Project\Entity\Project;
use App\Module\Readiness\Command\ReportFinding;
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
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

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
