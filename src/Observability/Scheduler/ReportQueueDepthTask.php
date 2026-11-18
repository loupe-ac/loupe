<?php

declare(strict_types=1);

namespace App\Observability\Scheduler;

use App\Observability\Command\ReportQueueDepthCommand;
use App\Observability\Command\ReportQueueDepthHandler;
use App\Observability\SentryDsnStatus;
use Psr\Log\LoggerInterface;
use Sentry\CheckInStatus;
use Sentry\MonitorConfig;
use Sentry\MonitorSchedule;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Sends the queue gauges to Sentry once a minute, inside a Sentry cron
 * check-in. A metric monitor with no data never fires, so the cron monitor
 * is what reports a dead worker or scheduler. `app:report-queue-depth` is the
 * manual backstop and sends no check-in.
 */
#[AsCronTask('%app.observability.queue_depth_schedule%')]
final readonly class ReportQueueDepthTask
{
    public const string MONITOR_SLUG = 'report-queue-depth';

    public function __construct(
        private ReportQueueDepthHandler $reportQueueDepth,

        #[Autowire('%env(default::SENTRY_DSN)%')]
        private ?string $dsn,

        #[Autowire(param: 'app.observability.queue_depth_schedule')]
        private string $schedule,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        if (SentryDsnStatus::Valid !== SentryDsnStatus::of($this->dsn)) {
            ($this->reportQueueDepth)(new ReportQueueDepthCommand());

            return;
        }

        $config = new MonitorConfig(
            MonitorSchedule::crontab($this->schedule),
            checkinMargin: 5,
            maxRuntime: 5,
            timezone: 'UTC',
            failureIssueThreshold: 3,
            recoveryThreshold: 1,
        );

        $checkInId = $this->checkIn(CheckInStatus::inProgress(), $config);
        $start = microtime(true);

        try {
            ($this->reportQueueDepth)(new ReportQueueDepthCommand());
        } catch (\Throwable $e) {
            $this->checkIn(CheckInStatus::error(), $config, microtime(true) - $start, $checkInId);

            throw $e;
        }

        $this->checkIn(CheckInStatus::ok(), $config, microtime(true) - $start, $checkInId);
    }

    private function checkIn(CheckInStatus $status, MonitorConfig $config, ?float $duration = null, ?string $checkInId = null): ?string
    {
        try {
            return \Sentry\captureCheckIn(self::MONITOR_SLUG, $status, $duration, $config, $checkInId);
        } catch (\Throwable $e) {
            $this->logger->warning('observability.check_in_failed', ['slug' => self::MONITOR_SLUG, 'status' => (string) $status, 'exception' => $e]);

            return null;
        }
    }
}
