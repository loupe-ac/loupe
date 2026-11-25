<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Project\Entity\Project;
use App\Module\Readiness\Command\DiscoveryRunning;
use App\Module\Readiness\Command\StartDiscoveryCommand;
use App\Module\Readiness\Command\StartDiscoveryHandler;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Event\DiscoveryReportWithdrawn;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use App\Module\Review\Entity\Document;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Readiness\DiscoveryScenario;
use App\Tests\Support\RecordingAuditor;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class StartDiscoveryHandlerTest extends KernelTestCase
{
    use DiscoveryScenario;

    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->audit = RecordingAuditor::installedIn(self::getContainer());
    }

    public function test_a_start_makes_a_run_with_no_card_and_opens_its_work_request(): void
    {
        $project = $this->liveProject();

        $run = $this->handler()(new StartDiscoveryCommand($project, Actor::Human));

        self::assertSame(DiscoveryRunState::Requested, $run->state);
        self::assertNull($run->card);
        $cards = self::getContainer()->get(CardRepository::class);
        self::assertInstanceOf(CardRepository::class, $cards);
        self::assertSame([], $cards->findBy(['project' => $project]));
        $requests = $this->discoveryRequests($run);
        self::assertCount(1, $requests);
        $request = $requests[0];
        self::assertSame(
            ['discovery', 'discovery', 'subject-discovery', null, true, WorkRequestState::Open],
            [$request->kind, $request->ruleId, $request->capability, $request->cardNumber, $request->failWhenNoTaker, $request->state],
        );
        self::assertSame(file_get_contents(\dirname(__DIR__, 4).'/config/workflows/app/prompts/discovery.md'), $request->prompt);
        self::assertTrue($this->storedRun($run)->workRequestId?->equals($request->id));
        $started = array_values(array_filter($this->audit->sink->events, static fn ($event): bool => 'readiness.discovery_started' === $event->operation));
        self::assertCount(1, $started);
        self::assertSame((string) $run->id, $started[0]->context['discoveryRunId']);
        self::assertSame((string) $request->id, $started[0]->context['workRequestId']);
        self::assertSame('human', $started[0]->context['reporter']);
    }

    /** The open announces the request before it returns, so the expiry reaches the run while the start has no request id. */
    public function test_a_request_that_expires_inside_the_open_leaves_the_run_failed_and_stores_its_id(): void
    {
        $project = $this->boundProject();
        $bridge = $this->discoveryBridge($project);
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $opened = 0;
        // The bridge stops serving the project after the gate passed, so the no-taker listener expires the request.
        $dispatcher->addListener(WorkRequestChanged::class, function (WorkRequestChanged $event) use ($bridge, &$opened): void {
            if (WorkRequestState::Open === $event->state) {
                ++$opened;
                $bridge->projects = [];
                $this->em()->flush();
            }
        });

        $run = $this->handler()(new StartDiscoveryCommand($project, Actor::Human));

        self::assertSame(1, $opened);
        $stored = $this->storedRun($run);
        self::assertSame([DiscoveryRunState::Failed, WorkRequest::REASON_NO_TAKER], [$stored->state, $stored->failureReason]);
        $requests = $this->discoveryRequests($stored);
        self::assertCount(1, $requests);
        self::assertSame(WorkRequestState::Expired, $requests[0]->state);
        self::assertTrue($stored->workRequestId?->equals($requests[0]->id));
    }

    /** The request expires inside the open, and a listener of the expiry throws before the subject handler hears of it. */
    public function test_a_start_that_throws_after_its_request_expired_leaves_the_run_failed(): void
    {
        $project = $this->boundProject();
        $bridge = $this->discoveryBridge($project);
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $expired = 0;
        $dispatcher->addListener(WorkRequestChanged::class, function (WorkRequestChanged $event) use ($bridge, &$expired): void {
            if (WorkRequestState::Open === $event->state) {
                $bridge->projects = [];
                $this->em()->flush();
            }
            if (WorkRequestState::Expired === $event->state) {
                ++$expired;
                throw new \RuntimeException('The announcement failed.');
            }
        }, 100);

        try {
            $this->handler()(new StartDiscoveryCommand($project, Actor::Human));
            self::fail('The start must throw.');
        } catch (\RuntimeException $e) {
            self::assertSame('The announcement failed.', $e->getMessage());
        }

        self::assertSame(1, $expired);
        $row = $this->em()->getConnection()->fetchAssociative('SELECT state, failure_reason FROM discovery_runs WHERE project_id = ?', [(string) $project->id]);
        self::assertSame(['state' => 'failed', 'failure_reason' => StartDiscoveryHandler::REQUEST_FAILED], $row);
    }

    /** A bridge whose discovery entry runs cards only cannot take work about a discovery run. */
    public function test_a_bridge_that_runs_discovery_for_cards_only_is_refused(): void
    {
        $project = $this->boundProject();
        $this->discoveryBridge($project, ['discovery']);

        $this->assertRefused($project, ['bridge' => StartDiscoveryHandler::NO_CAPABLE_BRIDGE_APP_PROMPTS]);
    }

    public function test_a_bridge_that_maps_the_discovery_subject_passes(): void
    {
        $project = $this->boundProject();
        $bridge = $this->discoveryBridge($project, ['discovery:discovery']);
        $bridge->capabilities = [...$bridge->capabilities ?? [], 'subject-discovery'];
        $this->em()->flush();

        $run = $this->handler()(new StartDiscoveryCommand($project, Actor::Human));

        self::assertSame(DiscoveryRunState::Requested, $this->storedRun($run)->state);
    }

    public function test_a_project_with_no_live_bridge_is_refused(): void
    {
        $project = $this->boundProject();
        $this->discoveryBridge($project, lastSeenAt: new \DateTimeImmutable('-1 day'));

        $this->assertRefused($project, ['bridge' => StartDiscoveryHandler::NO_LIVE_BRIDGE]);
    }

    public function test_a_project_with_no_workflow_is_refused(): void
    {
        $project = $this->workflowProject('discovery-start-no-workflow');
        $this->discoveryBridge($project);

        $this->assertRefused($project, ['workflow' => StartDiscoveryHandler::NO_WORKFLOW]);
    }

    public function test_a_project_whose_board_automation_is_off_is_refused(): void
    {
        $project = $this->liveProject();
        $this->em()->persist(new BoardAutomationSettings($project, enabled: false));
        $this->em()->flush();

        $this->assertRefused($project, ['automation' => StartDiscoveryHandler::AUTOMATION_OFF]);
    }

    /** @return iterable<string, array{DiscoveryRunState}> */
    public static function liveStates(): iterable
    {
        yield 'requested' => [DiscoveryRunState::Requested];
        yield 'revising' => [DiscoveryRunState::Revising];
    }

    #[DataProvider('liveStates')]
    public function test_a_start_while_a_run_is_live_names_that_run(DiscoveryRunState $state): void
    {
        $project = $this->liveProject();
        $live = $this->discoveryRun($project, $state);

        try {
            $this->handler()(new StartDiscoveryCommand($project, Actor::Human));
            self::fail('A second start must be refused while a run is live.');
        } catch (DiscoveryRunning $e) {
            self::assertSame((string) $live->id, (string) $e->runId);
        }

        self::assertCount(1, $this->runs($project));
    }

    public function test_a_start_after_a_failed_run_makes_a_new_run(): void
    {
        $project = $this->liveProject();
        $this->discoveryRun($project, DiscoveryRunState::Failed);

        $second = $this->handler()(new StartDiscoveryCommand($project, Actor::Human));

        self::assertSame(DiscoveryRunState::Requested, $second->state);
        self::assertCount(2, $this->runs($project));
    }

    public function test_a_start_supersedes_a_reported_run_archives_its_report_and_withdraws_it(): void
    {
        $project = $this->liveProject();
        $reported = $this->discoveryRun($project, DiscoveryRunState::Reported, '2026-10-02 10:00:00');
        $report = new Document($project->owner, $project, 'Report');
        $report->addVersion('x', '<p>x</p>');
        $this->em()->persist($report);
        $reported->reportDocument = $report;
        $done = $this->discoveryRun($project, DiscoveryRunState::Done, '2026-10-02 09:00:00');
        // The verdict or the report committed after this entity was loaded.
        $reported->state = DiscoveryRunState::Requested;
        $this->em()->flush();
        $this->em()->getConnection()->executeStatement("UPDATE discovery_runs SET state = 'reported' WHERE id = ?", [(string) $reported->id]);
        $withdrawn = [];
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(DiscoveryReportWithdrawn::class, static function (DiscoveryReportWithdrawn $event) use (&$withdrawn): void {
            $withdrawn[] = [(string) $event->projectId, (string) $event->runId];
        });

        $this->handler()(new StartDiscoveryCommand($project, Actor::Human));

        self::assertSame([[(string) $project->id, (string) $reported->id]], $withdrawn);
        $stored = $this->storedRun($reported);
        self::assertSame(DiscoveryRunState::Superseded, $stored->state);
        self::assertNotNull($stored->endedAt);
        self::assertNotNull($stored->reportDocument?->archivedAt);
        self::assertSame('Replaced by a newer discovery run', $stored->reportDocument->archiveReason);
        self::assertSame(DiscoveryRunState::Done, $this->storedRun($done)->state);
    }

    private function boundProject(): Project
    {
        $project = $this->workflowProject('discovery-start');
        $this->bindLifecycle($project);

        return $project;
    }

    private function liveProject(): Project
    {
        $project = $this->boundProject();
        $this->discoveryBridge($project);

        return $project;
    }

    /** @param array<string, string> $errors */
    private function assertRefused(Project $project, array $errors): void
    {
        try {
            $this->handler()(new StartDiscoveryCommand($project, Actor::Human));
            self::fail('The start must be refused.');
        } catch (DomainErrors $e) {
            self::assertSame($errors, $e->errors);
        }

        self::assertSame([], $this->runs($project));
    }

    private function handler(): StartDiscoveryHandler
    {
        $handler = self::getContainer()->get(StartDiscoveryHandler::class);
        self::assertInstanceOf(StartDiscoveryHandler::class, $handler);

        return $handler;
    }

    /** @return list<DiscoveryRun> */
    private function runs(Project $project): array
    {
        $repository = self::getContainer()->get(DiscoveryRunRepository::class);
        self::assertInstanceOf(DiscoveryRunRepository::class, $repository);

        return array_values($repository->findBy(['project' => $project]));
    }
}
