<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Bridge\Command\WriteWorkerRunFactsCommand;
use App\Module\Bridge\Command\WriteWorkerRunFactsHandler;
use App\Module\Bridge\Event\WorkerRunFactsWritten;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\WorkerRunFactWriter;
use App\Module\Insights\Messenger\RefreshProjectInsights;
use App\Module\Project\Repository\ProjectRepository;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\DispatchedEvents;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class WriteWorkerRunFactsHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_the_job_writes_the_fact_row_and_tells_the_dashboard(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'facts-job@example.com'), 'Facts job');
        $run = $this->seedRun($em, $project);
        $written = DispatchedEvents::of(self::getContainer(), WorkerRunFactsWritten::class);
        $transport = self::getContainer()->get('messenger.transport.insights');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        $this->handler()(new WriteWorkerRunFactsCommand($project->requireId(), [$run->id ?? throw new \LogicException()]));

        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_worker_run_facts WHERE run_id = ?', [(string) $run->id]));
        self::assertCount(1, $written->events());
        self::assertSame([(string) $run->id], $written->events()[0]->runIds);
        self::assertSame(0, $written->transactionDepths()[0] - $this->baseDepth($em), 'It tells after the commit.');
        $kinds = array_map(static fn ($envelope): ?string => $envelope->getMessage() instanceof RefreshProjectInsights ? $envelope->getMessage()->kind : null, $transport->getSent());
        self::assertContains('runs', $kinds);
    }

    public function test_a_second_write_of_an_unchanged_row_changes_nothing(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'facts-twice@example.com'), 'Facts twice');
        $run = $this->seedRun($em, $project);
        $writer = self::getContainer()->get(WorkerRunFactWriter::class);
        self::assertInstanceOf(WorkerRunFactWriter::class, $writer);
        $runId = $run->id ?? throw new \LogicException();

        self::assertSame(0, $writer->upsert([$runId]), 'The flush of the seed already wrote the row.');
        $em->getConnection()->executeStatement('UPDATE bridge_worker_runs SET idle_gap_ms = 7 WHERE id = ?', [(string) $runId]);

        self::assertSame(1, $writer->upsert([$runId]));
        self::assertSame(0, $writer->upsert([$runId]));
    }

    public function test_a_job_for_a_deleted_project_writes_nothing(): void
    {
        self::bootKernel();

        $runId = Uuid::v7();

        $this->handler()(new WriteWorkerRunFactsCommand(Uuid::v7(), [$runId]));

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_worker_run_facts WHERE run_id = ?', [(string) $runId]));
    }

    public function test_the_run_key_lock_is_held_until_the_transaction_ends(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'facts-lock@example.com'), 'Facts lock');
        $runs = self::getContainer()->get(WorkerRunRepository::class);
        self::assertInstanceOf(WorkerRunRepository::class, $runs);
        $bridgeId = Uuid::v7();
        $runKey = Uuid::v7();

        $held = static fn (): int => (int) $em->getConnection()->fetchOne("SELECT COUNT(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()");
        $before = $held();
        $runs->lockRunKey($project, $bridgeId, $runKey);

        self::assertSame($before + 1, $held());
    }

    public function test_the_report_lock_leaves_a_second_report_lock_alone(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'facts-report-lock@example.com'), 'Facts report lock');
        $projects = self::getContainer()->get(ProjectRepository::class);
        self::assertInstanceOf(ProjectRepository::class, $projects);

        $projects->lockForReport($project);
        $projects->lockForReport($project);

        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM projects WHERE id = ?', [(string) $project->id]));
    }

    private function handler(): WriteWorkerRunFactsHandler
    {
        $handler = self::getContainer()->get(WriteWorkerRunFactsHandler::class);
        self::assertInstanceOf(WriteWorkerRunFactsHandler::class, $handler);

        return $handler;
    }

    private function baseDepth(\Doctrine\ORM\EntityManagerInterface $em): int
    {
        return $em->getConnection()->getTransactionNestingLevel();
    }
}
