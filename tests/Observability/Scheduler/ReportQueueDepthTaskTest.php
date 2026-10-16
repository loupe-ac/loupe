<?php

declare(strict_types=1);

namespace App\Tests\Observability\Scheduler;

use App\Observability\QueueDepthReader;
use App\Observability\Scheduler\ReportQueueDepthTask;
use App\Tests\Support\ScheduledTasks;
use Sentry\ClientBuilder;
use Sentry\Metrics\Types\Metric;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReportQueueDepthTaskTest extends KernelTestCase
{
    private const string DSN = 'https://key@o0.ingest.sentry.io/1';

    private HubInterface $hub;

    protected function setUp(): void
    {
        $this->hub = SentrySdk::getCurrentHub();
    }

    protected function tearDown(): void
    {
        SentrySdk::setCurrentHub($this->hub);
        parent::tearDown();
    }

    public function test_it_is_registered_on_the_default_schedule_every_minute(): void
    {
        self::bootKernel();

        self::assertSame(
            '* * * * *',
            ScheduledTasks::cronExpressions(self::getContainer())[ReportQueueDepthTask::class] ?? null,
        );
    }

    public function test_it_sends_two_gauges_for_each_queue_with_a_usable_dsn(): void
    {
        self::bootKernel();
        $task = $this->task(self::DSN);
        $seen = $this->captureMetrics();

        $task();

        $names = array_map(static fn (Metric $m): string => $m->getName(), $seen->metrics);
        self::assertCount(8, $names);
        self::assertSame(4, \count(array_keys($names, 'messenger.queue.pending', true)));
        self::assertSame(4, \count(array_keys($names, 'messenger.queue.oldest_age', true)));
        $queues = array_values(array_unique(array_map(static fn (Metric $m): mixed => $m->getAttributes()->toSimpleArray()['queue_name'], $seen->metrics)));
        self::assertEqualsCanonicalizing(['default', 'workflow', 'async_low', 'failed'], $queues);
    }

    public function test_it_does_nothing_without_a_usable_dsn(): void
    {
        self::bootKernel();
        $off = $this->task(null);
        $malformed = $this->task('not a dsn');
        $seen = $this->captureMetrics();

        $off();
        $malformed();

        self::assertSame([], $seen->metrics);
    }

    private function task(?string $dsn): ReportQueueDepthTask
    {
        return new ReportQueueDepthTask(self::getContainer()->get(QueueDepthReader::class), $dsn);
    }

    private function captureMetrics(): \stdClass
    {
        $seen = new \stdClass();
        $seen->metrics = [];
        $client = ClientBuilder::create([
            'dsn' => self::DSN,
            'default_integrations' => false,
            'before_send_metric' => static function (Metric $metric) use ($seen): null {
                $seen->metrics[] = $metric;

                return null;
            },
        ])->getClient();
        SentrySdk::setCurrentHub(new Hub($client));

        return $seen;
    }
}
