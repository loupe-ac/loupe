<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Bridge\Command\ReportToolCallsCommand;
use App\Module\Bridge\Command\ReportToolCallsHandler;
use App\Module\Bridge\ValueObject\WorkerRunToolCallKind;
use App\Module\Bridge\ValueObject\WorkerRunToolCallReport;
use App\Module\Insights\Messenger\RefreshProjectInsights;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class ReportToolCallsHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_a_report_stores_the_bucket_times_of_the_run(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'report-buckets-'.uniqid().'@example.com');
        $project = $this->project($em, $owner, 'Report buckets');
        $runKey = Uuid::v4();
        $run = $this->seedRun($em, $project, runKey: $runKey);

        $handler = self::getContainer()->get(ReportToolCallsHandler::class);
        self::assertInstanceOf(ReportToolCallsHandler::class, $handler);
        $result = $handler(new ReportToolCallsCommand($owner, (string) $project->id, $runKey, [
            $this->call(1, 0, 1000),
            $this->call(2, 500, 1000),
            $this->call(3, 5000, 200, inSubagent: true),
        ], null));

        self::assertSame(3, $result->stored);
        self::assertSame(
            [['bucket' => 'other', 'ms' => 1500]],
            $em->getConnection()->fetchAllAssociative('SELECT bucket, ms FROM bridge_worker_run_bucket_times WHERE run_id = :run', ['run' => (string) $run->id]),
        );

        $handler(new ReportToolCallsCommand($owner, (string) $project->id, $runKey, [$this->call(4, 10_000, 250)], null));

        self::assertSame(
            '1750',
            (string) $em->getConnection()->fetchOne('SELECT ms FROM bridge_worker_run_bucket_times WHERE run_id = :run AND bucket = \'other\'', ['run' => (string) $run->id]),
        );
    }

    public function test_a_report_for_an_unknown_run_stores_no_bucket_time(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'report-buckets-none-'.uniqid().'@example.com');
        $project = $this->project($em, $owner, 'Report buckets none');
        $before = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_worker_run_bucket_times');

        $handler = self::getContainer()->get(ReportToolCallsHandler::class);
        self::assertInstanceOf(ReportToolCallsHandler::class, $handler);
        $result = $handler(new ReportToolCallsCommand($owner, (string) $project->id, Uuid::v4(), [$this->call(1, 0, 1000)], null));

        self::assertFalse($result->runFound);
        self::assertSame($before, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_worker_run_bucket_times'));
    }

    public function test_a_report_asks_the_insights_worker_for_the_runs_and_the_bucket_times(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'report-insights-'.uniqid().'@example.com');
        $project = $this->project($em, $owner, 'Report insights');
        $runKey = Uuid::v4();
        $this->seedRun($em, $project, runKey: $runKey);
        $transport = self::getContainer()->get('messenger.transport.insights');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        $handler = self::getContainer()->get(ReportToolCallsHandler::class);
        self::assertInstanceOf(ReportToolCallsHandler::class, $handler);
        $handler(new ReportToolCallsCommand($owner, (string) $project->id, $runKey, [$this->call(1, 0, 1000)], null));

        $kinds = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(RefreshProjectInsights::class, $message);
            $kinds[] = $message->kind;
        }
        sort($kinds);
        self::assertSame(['bucket-times', 'runs'], $kinds);
    }

    private function call(int $seq, int $offsetMs, int $durationMs, bool $inSubagent = false): WorkerRunToolCallReport
    {
        return new WorkerRunToolCallReport(
            $seq,
            'Bash',
            WorkerRunToolCallKind::Shell,
            new \DateTimeImmutable('2026-01-01 10:00:00', new \DateTimeZone('UTC'))->modify(\sprintf('+%d milliseconds', $offsetMs)),
            $durationMs,
            false,
            $inSubagent,
            null,
            null,
            ['Bash(ls)'],
            null,
        );
    }
}
