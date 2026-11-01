<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Repository\WorkerRunBilledCostRepository;
use App\Module\Bridge\Service\WorkerRunBilledCostExporter;
use App\Module\Bridge\ValueObject\BilledCostReason;
use App\Module\Bridge\ValueObject\BilledCostState;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WorkerRunBilledCostExporterTest extends KernelTestCase
{
    use BridgeScenario;

    /** A row whose run the retention sweep took is still the account's data. */
    public function test_it_exports_the_billed_costs_of_the_account_alone(): void
    {
        self::bootKernel();
        $em = $this->em();
        $exporting = $this->user($em, 'billed-export-mine@example.com');
        $project = $this->project($em, $exporting, 'Billed Export');
        $runKey = Uuid::v4();
        $run = $this->seedRun($em, $project, runKey: $runKey);
        $this->seedBilledCost($em, $run, BilledCostState::Unknown, null, BilledCostReason::NoKey);
        $other = $this->seedRun($em, $this->project($em, $this->user($em, 'billed-export-other@example.com'), 'Other Billed'));
        $this->seedBilledCost($em, $other);
        $em->clear();

        $rows = iterator_to_array(new WorkerRunBilledCostExporter($this->billedCosts())->export($exporting), false);

        self::assertCount(1, $rows);
        self::assertSame($run->id?->toRfc4122(), $rows[0]['runId']);
        self::assertSame($runKey->toRfc4122(), $rows[0]['runKey']);
        self::assertSame('Billed Export', $rows[0]['project']);
        self::assertSame('unknown', $rows[0]['state']);
        self::assertSame('no-key', $rows[0]['reason']);
        self::assertNull($rows[0]['costUsd']);
        self::assertSame('worker_run_billed_costs.json', new WorkerRunBilledCostExporter($this->billedCosts())->filename());
    }

    private function billedCosts(): WorkerRunBilledCostRepository
    {
        $repository = self::getContainer()->get(WorkerRunBilledCostRepository::class);
        self::assertInstanceOf(WorkerRunBilledCostRepository::class, $repository);

        return $repository;
    }
}
