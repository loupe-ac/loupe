<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\EventListener;

use App\Module\Bridge\Event\BridgeAccountReadingsStored;
use App\Module\Bridge\Event\BucketTimesRecomputed;
use App\Module\Bridge\Event\ExperimentSetupChanged;
use App\Module\Bridge\Event\WorkerRunBilledCostRecorded;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\Event\WorkerRunToolCallsRecorded;
use App\Module\Bridge\Event\WorkerRunUsageRecorded;
use App\Module\Bridge\Event\WorkerRunWakesRecorded;
use App\Module\Insights\Messenger\RefreshProjectInsights;
use App\Module\Project\Event\ProjectCardsFinishedChanged;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/** The events that bring new data ask the worker to refresh the graphs that read it, after the grouping delay. */
final class RefreshInsightsListenersTest extends KernelTestCase
{
    private const string PROJECT = '0199c7a0-0000-7000-8000-000000000001';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->transport()->reset();
    }

    public function test_a_run_change_asks_for_the_runs(): void
    {
        $this->dispatch(new WorkerRunChanged(Uuid::fromString(self::PROJECT), [], []));

        $this->assertAsked('runs');
    }

    public function test_usage_tool_calls_wakes_and_billed_cost_ask_for_the_runs(): void
    {
        foreach (['0199c7a0-0000-7000-8000-000000000101' => WorkerRunUsageRecorded::class, '0199c7a0-0000-7000-8000-000000000102' => WorkerRunToolCallsRecorded::class, '0199c7a0-0000-7000-8000-000000000103' => WorkerRunBilledCostRecorded::class, '0199c7a0-0000-7000-8000-000000000104' => WorkerRunWakesRecorded::class] as $project => $class) {
            $this->transport()->reset();
            $this->dispatch(new $class($project));
            $this->assertAsked('runs', $project);
        }
    }

    public function test_a_bucket_time_recompute_asks_for_the_bucket_times(): void
    {
        $this->dispatch(new BucketTimesRecomputed(self::PROJECT));

        $this->assertAsked('bucket-times');
    }

    public function test_stored_readings_ask_once_for_each_project(): void
    {
        $this->dispatch(new BridgeAccountReadingsStored([self::PROJECT, '0199c7a0-0000-7000-8000-000000000002']));

        self::assertCount(2, $this->transport()->getSent());
    }

    public function test_a_card_that_finishes_asks_for_the_cards(): void
    {
        $this->dispatch(new ProjectCardsFinishedChanged(self::PROJECT));

        $this->assertAsked('cards');
    }

    public function test_an_experiment_setup_change_names_the_experiment(): void
    {
        $this->dispatch(new ExperimentSetupChanged(self::PROJECT, 'trial'));

        $message = $this->assertAsked('experiment');
        self::assertSame('trial', $message->experiment);
    }

    public function test_a_burst_of_one_kind_queues_one_message(): void
    {
        $this->dispatch(new WorkerRunUsageRecorded(self::PROJECT));
        $this->dispatch(new WorkerRunUsageRecorded(self::PROJECT));
        $this->dispatch(new WorkerRunToolCallsRecorded(self::PROJECT));

        self::assertCount(1, $this->transport()->getSent());
    }

    public function test_the_kinds_of_one_project_queue_apart(): void
    {
        $this->dispatch(new WorkerRunUsageRecorded(self::PROJECT));
        $this->dispatch(new BucketTimesRecomputed(self::PROJECT));

        self::assertCount(2, $this->transport()->getSent());
    }

    private function assertAsked(string $kind, string $project = self::PROJECT): RefreshProjectInsights
    {
        $sent = $this->transport()->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(RefreshProjectInsights::class, $message);
        self::assertSame($kind, $message->kind);
        self::assertSame($project, $message->projectId);
        $delay = $sent[0]->last(DelayStamp::class);
        self::assertInstanceOf(DelayStamp::class, $delay);
        self::assertSame(60000, $delay->getDelay());

        return $message;
    }

    private function dispatch(object $event): void
    {
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->dispatch($event);
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.insights');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
