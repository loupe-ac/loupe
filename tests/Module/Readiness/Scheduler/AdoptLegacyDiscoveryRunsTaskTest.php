<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Scheduler;

use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Project\Entity\Project;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Messenger\RequestDiscoveryRevision;
use App\Module\Readiness\Messenger\RequestDiscoveryRevisionHandler;
use App\Module\Readiness\Scheduler\AdoptLegacyDiscoveryRunsTask;
use App\Tests\Module\Readiness\DiscoveryScenario;
use App\Tests\Support\ScheduledTasks;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The runs that the previous image starts or changes while a deploy runs, which hold a card. */
final class AdoptLegacyDiscoveryRunsTaskTest extends KernelTestCase
{
    use DiscoveryScenario;

    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->project = $this->workflowProject('discovery-adopt');
        $this->discoveryBridge($this->project);
    }

    public function test_the_task_is_registered_on_the_default_schedule_every_minute(): void
    {
        self::assertSame(
            '* * * * *',
            ScheduledTasks::cronExpressions(self::getContainer())[AdoptLegacyDiscoveryRunsTask::class] ?? null,
        );
    }

    /** @return iterable<string, array{DiscoveryRunState, string}> */
    public static function liveRuns(): iterable
    {
        yield 'requested' => [DiscoveryRunState::Requested, 'discovery'];
        yield 'revising' => [DiscoveryRunState::Revising, 'discovery-revise'];
    }

    #[DataProvider('liveRuns')]
    public function test_a_live_card_request_moves_to_the_run(DiscoveryRunState $state, string $kind): void
    {
        $card = $this->card($this->project);
        $run = $this->discoveryRun($this->project, $state, card: $card);
        $request = $this->legacyCardRequest($card, $kind);
        $worker = $this->legacyWorkerRun($card, $request);

        $this->task();
        $this->task();

        $this->assertMovedToRun($run, $request, $worker);
        self::assertSame('open', $this->requestRow($request)['state']);
        $stored = $this->storedRun($run);
        self::assertSame($state, $stored->state);
        self::assertTrue($stored->workRequestId?->equals($request->id));
        self::assertCount(1, $this->discoveryRequests($stored));
    }

    #[DataProvider('liveRuns')]
    public function test_a_run_with_no_live_request_gets_one_request_about_the_run(DiscoveryRunState $state, string $kind): void
    {
        $run = $this->discoveryRun($this->project, $state, card: $this->card($this->project));

        $this->task();
        $this->task();

        $stored = $this->storedRun($run);
        $requests = $this->discoveryRequests($stored);
        self::assertCount(1, $requests);
        $request = $requests[0];
        self::assertSame(
            [$kind, $kind, 'subject-discovery', null, true, WorkRequestState::Open],
            [$request->kind, $request->ruleId, $request->capability, $request->cardNumber, $request->failWhenNoTaker, $request->state],
        );
        self::assertSame(file_get_contents(\dirname(__DIR__, 4).'/config/workflows/app/prompts/'.$kind.'.md'), $request->prompt);
        self::assertTrue($stored->workRequestId?->equals($request->id));
        self::assertSame($state, $stored->state);
    }

    /** The discovery request of the first pass can outlive the report, and it asks nobody for a revision. */
    public function test_a_revising_run_with_a_live_discovery_request_alone_gets_a_revise_request(): void
    {
        $run = $this->discoveryRun($this->project, DiscoveryRunState::Revising, card: $this->card($this->project));
        $first = $this->discoveryRequest($run);
        $first->state = WorkRequestState::Claimed;
        $this->em()->flush();

        $this->task();
        $this->task();

        $requests = $this->discoveryRequests($this->storedRun($run));
        self::assertEqualsCanonicalizing(['discovery', 'discovery-revise'], array_map(static fn (WorkRequest $request): string => $request->kind, $requests));
    }

    #[DataProvider('liveRuns')]
    public function test_a_copy_that_the_previous_image_opened_on_the_card_is_cancelled(DiscoveryRunState $state, string $kind): void
    {
        $card = $this->card($this->project);
        $run = $this->discoveryRun($this->project, $state, card: $card);
        $ofRun = $this->discoveryRequest($run, $kind);
        $copy = $this->legacyCardRequest($card, $kind, WorkRequestState::Claimed);
        $otherRule = $this->legacyCardRequest($card, 'implement');

        $this->task();
        $this->task();

        self::assertSame(['subject_type' => 'card', 'state' => 'cancelled'], $this->requestRow($copy));
        self::assertSame('open', $this->requestRow($ofRun)['state']);
        self::assertSame('open', $this->requestRow($otherRule)['state']);
        $stored = $this->storedRun($run);
        self::assertSame($state, $stored->state);
        self::assertCount(1, $this->discoveryRequests($stored));
    }

    public function test_a_run_with_no_card_and_a_run_that_ended_get_nothing(): void
    {
        $cardless = $this->discoveryRun($this->project);
        $this->task();

        self::assertSame([], $this->discoveryRequests($cardless));

        $card = $this->card($this->project);
        $reported = $this->discoveryRun($this->project, DiscoveryRunState::Reported, card: $card);
        $request = $this->legacyCardRequest($card);
        $this->task();

        self::assertSame(['subject_type' => 'card', 'state' => 'open'], $this->requestRow($request));
        self::assertSame([], $this->discoveryRequests($reported));
    }

    public function test_a_run_that_no_bridge_can_take_fails(): void
    {
        $project = $this->workflowProject('discovery-adopt-alone');
        $run = $this->discoveryRun($project, card: $this->card($project));

        $this->task();
        $this->task();

        $stored = $this->storedRun($run);
        self::assertSame([DiscoveryRunState::Failed, WorkRequest::REASON_NO_TAKER], [$stored->state, $stored->failureReason]);
        self::assertCount(1, $this->discoveryRequests($stored));
    }

    /** @return iterable<string, array{bool}> */
    public static function orders(): iterable
    {
        yield 'the task first' => [true];
        yield 'the message first' => [false];
    }

    #[DataProvider('orders')]
    public function test_the_task_and_a_queued_revise_message_open_one_request(bool $taskFirst): void
    {
        $run = $this->discoveryRun($this->project, DiscoveryRunState::Revising, card: $this->card($this->project));
        $handler = self::getContainer()->get(RequestDiscoveryRevisionHandler::class);
        self::assertInstanceOf(RequestDiscoveryRevisionHandler::class, $handler);
        $message = new RequestDiscoveryRevision((string) $run->id, null);

        $taskFirst ? $this->task() : $handler($message);
        $taskFirst ? $handler($message) : $this->task();

        $stored = $this->storedRun($run);
        $requests = $this->discoveryRequests($stored);
        self::assertCount(1, $requests);
        self::assertSame(WorkRequestState::Open, $requests[0]->state);
        self::assertTrue($stored->workRequestId?->equals($requests[0]->id));
        self::assertSame(DiscoveryRunState::Revising, $stored->state);
    }

    private function task(): void
    {
        $task = self::getContainer()->get(AdoptLegacyDiscoveryRunsTask::class);
        self::assertInstanceOf(AdoptLegacyDiscoveryRunsTask::class, $task);
        $task();
    }
}
