<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Repository\WorkerRunOutputChunkRepository;
use App\Module\Bridge\Service\WorkerRunOutputExporter;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WorkerRunOutputExporterTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_it_exports_the_output_of_the_account_alone(): void
    {
        self::bootKernel();
        $em = $this->em();
        $exporting = $this->user($em, 'output-export-mine@example.com');
        $runKey = Uuid::v4();
        $run = $this->seedRun($em, $this->project($em, $exporting, 'Output Export'), runKey: $runKey);
        $this->seedOutputChunk($run, "first\n");
        $this->seedOutputChunk($run, 'second', cut: true);
        $this->seedOutputChunk($this->seedRun($em, $this->project($em, $this->user($em, 'output-export-other@example.com'), 'Other Output')), 'not mine');
        $em->clear();

        $rows = iterator_to_array($this->exporter()->export($exporting), false);

        self::assertSame([[
            'project' => 'Output Export',
            'runKey' => $runKey->toRfc4122(),
            'length' => 12,
            'capped' => true,
            'text' => "first\nsecond",
        ]], $rows);
    }

    public function test_the_archive_entry_is_named_after_the_output(): void
    {
        self::bootKernel();

        self::assertSame('worker_run_output.json', $this->exporter()->filename());
    }

    private function exporter(): WorkerRunOutputExporter
    {
        $chunks = self::getContainer()->get(WorkerRunOutputChunkRepository::class);
        self::assertInstanceOf(WorkerRunOutputChunkRepository::class, $chunks);

        return new WorkerRunOutputExporter($chunks);
    }
}
