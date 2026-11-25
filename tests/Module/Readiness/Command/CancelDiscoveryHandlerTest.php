<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Readiness\Command\CancelDiscoveryCommand;
use App\Module\Readiness\Command\CancelDiscoveryHandler;
use App\Module\Readiness\Command\ReportFinding;
use App\Module\Readiness\Command\SubmitReadinessReportCommand;
use App\Module\Readiness\Command\SubmitReadinessReportHandler;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Service\ReadinessReportWriter;
use App\Tests\Module\Readiness\DiscoveryScenario;
use App\Tests\Support\RecordingAuditor;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CancelDiscoveryHandlerTest extends KernelTestCase
{
    use DiscoveryScenario;

    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->audit = RecordingAuditor::installedIn(self::getContainer());
    }

    /** @return iterable<string, array{DiscoveryRunState, string}> */
    public static function liveStates(): iterable
    {
        yield 'requested' => [DiscoveryRunState::Requested, 'discovery'];
        yield 'revising' => [DiscoveryRunState::Revising, 'discovery-revise'];
    }

    #[DataProvider('liveStates')]
    public function test_a_cancel_fails_the_live_run_names_the_person_and_cancels_its_request(DiscoveryRunState $state, string $kind): void
    {
        $project = $this->workflowProject('discovery-cancel');
        $run = $this->discoveryRun($project, $state);
        $request = $this->discoveryRequest($run, $kind);

        $this->cancel($run);

        $stored = $this->storedRun($run);
        self::assertSame(
            [DiscoveryRunState::Failed, CancelDiscoveryHandler::REASON, (string) $project->owner->id],
            [$stored->state, $stored->failureReason, (string) $stored->cancelledBy?->id],
        );
        self::assertNotNull($stored->endedAt);
        self::assertSame(WorkRequestState::Cancelled->value, $this->requestRow($request)['state']);
        self::assertSame(CancelDiscoveryHandler::REASON, $this->audit->record('readiness.discovery_failed')->context['reason']);
    }

    /** The start stopped between the stored run and its request, so the run waits for a request that never opens. */
    public function test_a_cancel_clears_a_requested_run_that_has_no_request(): void
    {
        $run = $this->discoveryRun($this->workflowProject('discovery-cancel-stuck'));

        $this->cancel($run);

        $stored = $this->storedRun($run);
        self::assertSame([DiscoveryRunState::Failed, CancelDiscoveryHandler::REASON, null], [$stored->state, $stored->failureReason, $stored->workRequestId]);
        self::assertNotNull($stored->cancelledBy);
    }

    /** @return iterable<string, array{DiscoveryRunState}> */
    public static function quietStates(): iterable
    {
        yield 'reported' => [DiscoveryRunState::Reported];
        yield 'failed' => [DiscoveryRunState::Failed];
        yield 'done' => [DiscoveryRunState::Done];
        yield 'superseded' => [DiscoveryRunState::Superseded];
    }

    #[DataProvider('quietStates')]
    public function test_a_cancel_with_nothing_live_does_nothing(DiscoveryRunState $state): void
    {
        $project = $this->workflowProject('discovery-cancel-quiet');
        $run = $this->discoveryRun($project, $state);
        $this->workerRun($run, $this->commandBridge($run), WorkerRunState::Running);

        $this->cancel($run);

        $stored = $this->storedRun($run);
        self::assertSame([$state, null, null], [$stored->state, $stored->failureReason, $stored->cancelledBy]);
        self::assertSame([], $this->commands());
    }

    public function test_a_cancel_asks_the_bridge_to_stop_each_open_worker_run_of_the_discovery_run(): void
    {
        $project = $this->workflowProject('discovery-cancel-stop');
        $run = $this->discoveryRun($project);
        $bridge = $this->commandBridge($run);
        $running = $this->workerRun($run, $bridge, WorkerRunState::Running);
        $queued = $this->workerRun($run, $bridge, WorkerRunState::Queued);
        $this->workerRun($run, $bridge, WorkerRunState::Succeeded);
        $this->workerRun($run, $bridge, WorkerRunState::Stopping);
        $this->workerRun($run, $bridge, WorkerRunState::Running, WorkerRunKind::Interactive);
        $other = $this->discoveryRun($project, DiscoveryRunState::Failed, '2026-10-01 12:00:00');
        $this->workerRun($other, $bridge, WorkerRunState::Running);

        $this->cancel($run);

        $commands = $this->commands();
        $stopped = array_map(static fn (BridgeCommand $command): string => (string) $command->workerRun?->id, $commands);
        sort($stopped);
        $expected = [(string) $running->id, (string) $queued->id];
        sort($expected);
        self::assertSame($expected, $stopped);
        foreach ($commands as $command) {
            self::assertSame(BridgeCommandKind::StopRun, $command->kind);
            self::assertSame((string) $project->owner->id, (string) $command->requestedBy?->id);
        }
    }

    public function test_a_refused_stop_does_not_stop_the_cancel_or_the_next_stop(): void
    {
        $project = $this->workflowProject('discovery-cancel-refused');
        $run = $this->discoveryRun($project);
        // No bridge row holds this id, so the stop is refused.
        $this->workerRun($run, Uuid::v4(), WorkerRunState::Running);
        $reachable = $this->workerRun($run, $this->commandBridge($run), WorkerRunState::Running);

        $this->cancel($run);

        self::assertSame(DiscoveryRunState::Failed, $this->storedRun($run)->state);
        self::assertSame([(string) $reachable->id], array_map(static fn (BridgeCommand $command): string => (string) $command->workerRun?->id, $this->commands()));
    }

    public function test_a_report_after_a_cancel_is_refused(): void
    {
        $project = $this->workflowProject('discovery-cancel-late');
        $run = $this->discoveryRun($project);
        $this->cancel($run);
        $submit = self::getContainer()->get(SubmitReadinessReportHandler::class);
        self::assertInstanceOf(SubmitReadinessReportHandler::class, $submit);

        try {
            $submit(new SubmitReadinessReportCommand($run->project, (string) $run->id, 'Workflow', [new ReportFinding('Tests', ReadinessReportWriter::READY, 'They pass.')]));
            self::fail('A cancelled run takes no report.');
        } catch (DomainErrors $e) {
            self::assertSame([SubmitReadinessReportHandler::RUN_NOT_REQUESTED], array_values($e->errors));
        }

        self::assertNull($this->storedRun($run)->reportDocument);
    }

    private function cancel(DiscoveryRun $run): void
    {
        $handler = self::getContainer()->get(CancelDiscoveryHandler::class);
        self::assertInstanceOf(CancelDiscoveryHandler::class, $handler);
        $handler(new CancelDiscoveryCommand($run->project, $run->project->owner));
    }

    /** @return Uuid the id of a bridge that takes commands */
    private function commandBridge(DiscoveryRun $run): Uuid
    {
        $bridge = $this->discoveryBridge($run->project);
        $bridge->capabilities = [...$bridge->capabilities ?? [], Bridge::CAPABILITY_COMMANDS];
        $this->em()->flush();

        return $bridge->id;
    }

    private function workerRun(DiscoveryRun $run, Uuid $bridgeId, WorkerRunState $state, WorkerRunKind $kind = WorkerRunKind::Worker): WorkerRun
    {
        $worker = new WorkerRun(
            project: $run->project,
            bridgeId: $bridgeId,
            subjectType: DiscoveryRun::SUBJECT_TYPE,
            subjectId: $run->id ?? throw new \LogicException('A stored run has an id.'),
            cardNumber: null,
            workKind: 'discovery',
            state: $state,
            runKey: Uuid::v4(),
            sessionId: Uuid::v4(),
            kind: $kind,
        );
        $this->em()->persist($worker);
        $this->em()->flush();

        return $worker;
    }

    /** @return list<BridgeCommand> */
    private function commands(): array
    {
        $repository = self::getContainer()->get(BridgeCommandRepository::class);
        self::assertInstanceOf(BridgeCommandRepository::class, $repository);

        return array_values($repository->findAll());
    }
}
