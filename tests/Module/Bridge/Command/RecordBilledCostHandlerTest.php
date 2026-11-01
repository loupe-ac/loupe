<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Bridge\Command\RecordBilledCostCommand;
use App\Module\Bridge\Command\RecordBilledCostHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunBilledCost;
use App\Module\Bridge\ValueObject\BilledCostReason;
use App\Module\Bridge\ValueObject\BilledCostState;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\RecordingAuditor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class RecordBilledCostHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_a_billed_cost_fills_the_row_and_the_fact_row_of_the_run(): void
    {
        self::bootKernel();
        [$run, $row] = $this->pendingRun('record-billed');

        ($this->handler())(new RecordBilledCostCommand($row->runId, BilledCostState::Billed, costUsd: '0.036000', attempts: 2));

        $stored = $this->stored($row);
        self::assertSame(BilledCostState::Billed, $stored->state);
        self::assertSame('0.036000', $stored->costUsd);
        self::assertSame(2, $stored->attempts);
        self::assertSame(36_000, (int) $this->em()->getConnection()->fetchOne('SELECT cost_micro_usd FROM bridge_worker_run_facts WHERE run_id = ?', [(string) $run->id]));
    }

    public function test_a_pending_read_keeps_the_total_the_next_read_must_confirm(): void
    {
        self::bootKernel();
        [, $row] = $this->pendingRun('record-pending');

        ($this->handler())(new RecordBilledCostCommand($row->runId, BilledCostState::Pending, firstReadCost: '0.020000', attempts: 1));

        $stored = $this->stored($row);
        self::assertSame(BilledCostState::Pending, $stored->state);
        self::assertSame('0.020000', $stored->firstReadCost);
        self::assertNull($stored->costUsd);
    }

    public function test_an_unknown_cost_keeps_its_reason(): void
    {
        self::bootKernel();
        [, $row] = $this->pendingRun('record-unknown');

        ($this->handler())(new RecordBilledCostCommand($row->runId, BilledCostState::Unknown, BilledCostReason::KeyRefused, attempts: 1));

        $stored = $this->stored($row);
        self::assertSame(BilledCostState::Unknown, $stored->state);
        self::assertSame(BilledCostReason::KeyRefused, $stored->reason);
    }

    public function test_a_final_row_never_changes(): void
    {
        self::bootKernel();
        [, $row] = $this->pendingRun('record-final');
        ($this->handler())(new RecordBilledCostCommand($row->runId, BilledCostState::Billed, costUsd: '0.036000', attempts: 2));

        ($this->handler())(new RecordBilledCostCommand($row->runId, BilledCostState::Unknown, BilledCostReason::NoAnswer, attempts: 3));

        $stored = $this->stored($row);
        self::assertSame(BilledCostState::Billed, $stored->state);
        self::assertSame('0.036000', $stored->costUsd);
        self::assertNull($stored->reason);
    }

    public function test_a_row_that_does_not_exist_is_ignored(): void
    {
        self::bootKernel();

        ($this->handler())(new RecordBilledCostCommand(Uuid::v7(), BilledCostState::Unknown, BilledCostReason::NoKey));

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_worker_run_billed_costs'));
    }

    public function test_a_swept_run_keeps_its_billed_cost_and_gets_no_fact_row(): void
    {
        self::bootKernel();
        [$run, $row] = $this->pendingRun('record-swept');
        $connection = $this->em()->getConnection();
        $connection->executeStatement('DELETE FROM bridge_worker_run_facts');
        $connection->executeStatement('DELETE FROM bridge_worker_runs WHERE id = ?', [(string) $run->id]);

        ($this->handler())(new RecordBilledCostCommand($row->runId, BilledCostState::Billed, costUsd: '0.100000', attempts: 1));

        self::assertSame('0.100000', $connection->fetchOne('SELECT cost_usd FROM bridge_worker_run_billed_costs WHERE run_id = ?', [(string) $row->runId]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM bridge_worker_run_facts'));
    }

    public function test_a_final_state_is_audited(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        [, $row] = $this->pendingRun('record-audit');

        ($this->handler())(new RecordBilledCostCommand($row->runId, BilledCostState::Unknown, BilledCostReason::NoKey));

        self::assertSame('no-key', $audit->record('bridge.billed_cost_recorded')->context['reason']);
    }

    /** @return array{WorkerRun, WorkerRunBilledCost} */
    private function pendingRun(string $name): array
    {
        $em = $this->em();
        $project = $this->project($em, $this->user($em, $name.'@example.com'), 'Project '.$name);
        $run = $this->seedRun($em, $project, runKey: Uuid::v4());

        return [$run, $this->seedBilledCost($em, $run, BilledCostState::Pending, null)];
    }

    private function stored(WorkerRunBilledCost $row): WorkerRunBilledCost
    {
        $this->em()->clear();

        return $this->em()->find(WorkerRunBilledCost::class, $row->runId) ?? throw new \LogicException('The row exists.');
    }

    private function handler(): RecordBilledCostHandler
    {
        $handler = self::getContainer()->get(RecordBilledCostHandler::class);
        self::assertInstanceOf(RecordBilledCostHandler::class, $handler);

        return $handler;
    }
}
