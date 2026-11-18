<?php

declare(strict_types=1);

namespace App\Tests\Observability\Scheduler;

use App\Observability\Command\ReportQueueDepthHandler;
use App\Observability\QueueDepthReader;
use App\Observability\Scheduler\ReportQueueDepthTask;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sentry\CheckIn;
use Sentry\CheckInStatus;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Metrics\Types\Metric;
use Sentry\MonitorConfig;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;

final class ReportQueueDepthTaskCheckInTest extends TestCase
{
    private const string DSN = 'https://key@o0.ingest.sentry.io/1';

    private HubInterface $hub;

    /** @var list<CheckIn> */
    private array $checkIns = [];

    /** @var list<Metric> */
    private array $metrics = [];

    protected function setUp(): void
    {
        $this->hub = SentrySdk::getCurrentHub();
        $client = ClientBuilder::create([
            'dsn' => self::DSN,
            'default_integrations' => false,
            'before_send_check_in' => function (Event $event): null {
                $checkIn = $event->getCheckIn();
                self::assertNotNull($checkIn);
                $this->checkIns[] = $checkIn;

                return null;
            },
            'before_send_metric' => function (Metric $metric): null {
                $this->metrics[] = $metric;

                return null;
            },
        ])->getClient();
        SentrySdk::setCurrentHub(new Hub($client));
    }

    protected function tearDown(): void
    {
        SentrySdk::setCurrentHub($this->hub);
    }

    public function test_a_run_sends_in_progress_then_ok_with_the_monitor_config(): void
    {
        $this->task($this->handler([]), self::DSN)();

        self::assertCount(2, $this->checkIns);
        [$start, $end] = $this->checkIns;
        self::assertSame('report-queue-depth', $start->getMonitorSlug());
        self::assertSame('in_progress', (string) $start->getStatus());
        self::assertSame('report-queue-depth', $end->getMonitorSlug());
        self::assertSame('ok', (string) $end->getStatus());
        self::assertSame($start->getId(), $end->getId());
        self::assertIsFloat($end->getDuration());

        foreach ($this->checkIns as $checkIn) {
            self::assertSame([
                'schedule' => ['type' => 'crontab', 'value' => '*/2 * * * *', 'unit' => ''],
                'checkin_margin' => 5,
                'max_runtime' => 5,
                'timezone' => 'UTC',
                'failure_issue_threshold' => 3,
                'recovery_threshold' => 1,
            ], $checkIn->getMonitorConfig()?->toArray());
        }
        self::assertNotEmpty($this->metrics);
    }

    public function test_a_failed_run_sends_error_and_rethrows(): void
    {
        $failure = new \RuntimeException('database down');

        try {
            $this->task($this->handler($failure), self::DSN)();
            self::fail('The task swallowed the exception.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertCount(2, $this->checkIns);
        self::assertSame('in_progress', (string) $this->checkIns[0]->getStatus());
        self::assertSame('error', (string) $this->checkIns[1]->getStatus());
        self::assertSame($this->checkIns[0]->getId(), $this->checkIns[1]->getId());
    }

    public function test_it_sends_no_check_in_without_a_usable_dsn(): void
    {
        $this->task($this->handler([]), null)();
        $this->task($this->handler([]), 'not a dsn')();

        self::assertNotEmpty($this->metrics, 'The handler must still run.');
        self::assertSame([], $this->checkIns);
    }

    public function test_a_failing_check_in_does_not_fail_the_run(): void
    {
        $this->failCheckIns();

        $this->task($this->handler([]), self::DSN)();

        self::assertNotEmpty($this->metrics);
    }

    public function test_a_failing_check_in_does_not_hide_the_handler_exception(): void
    {
        $this->failCheckIns();
        $failure = new \RuntimeException('database down');

        $this->expectExceptionObject($failure);

        $this->task($this->handler($failure), self::DSN)();
    }

    private function failCheckIns(): void
    {
        $client = SentrySdk::getCurrentHub()->getClient();
        SentrySdk::setCurrentHub(new class($client) extends Hub {
            #[\Override]
            public function captureCheckIn(string $slug, CheckInStatus $status, $duration = null, ?MonitorConfig $monitorConfig = null, ?string $checkInId = null): ?string
            {
                throw new \RuntimeException('transport down');
            }
        });
    }

    /**
     * @param list<array<string, mixed>>|\Throwable $rows
     */
    private function handler(array|\Throwable $rows): ReportQueueDepthHandler
    {
        $connection = $this->createStub(Connection::class);
        if ($rows instanceof \Throwable) {
            $connection->method('fetchAllAssociative')->willThrowException($rows);
        } else {
            $connection->method('fetchAllAssociative')->willReturn($rows);
        }

        return new ReportQueueDepthHandler(new QueueDepthReader($connection), self::DSN);
    }

    private function task(ReportQueueDepthHandler $handler, ?string $dsn): ReportQueueDepthTask
    {
        return new ReportQueueDepthTask($handler, $dsn, '*/2 * * * *', new NullLogger());
    }
}
