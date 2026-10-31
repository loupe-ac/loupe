<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Repository\BridgeAccountReadingRepository;
use App\Module\Bridge\Service\BridgeAccountReadingExporter;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BridgeAccountReadingExporterTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_it_exports_the_readings_of_the_account_alone(): void
    {
        self::bootKernel();
        $em = $this->em();
        $exporting = $this->user($em, 'readings-export-mine@example.com');
        $shared = Uuid::v4();
        $bridge = $this->seedBridge($em, $exporting, $shared);
        $this->seedAccountReading($em, $bridge, '2026-10-07 12:01:00', usedPercent: null, limited: true);
        $this->seedAccountReading($em, $bridge, '2026-10-07 12:00:00', 'work', 'week', 29.5, '2026-10-09 00:00:00');
        $this->seedAccountReading($em, $this->seedBridge($em, $this->user($em, 'readings-export-other@example.com'), $shared), '2026-10-07 12:00:00');
        $em->clear();

        $rows = iterator_to_array($this->exporter()->export($exporting), false);

        self::assertCount(2, $rows);
        self::assertSame([
            'bridgeId' => $shared->toRfc4122(),
            'account' => 'work',
            'window' => 'session',
            'usedPercent' => null,
            'resetsAt' => '2026-10-07T17:00:00+00:00',
            'limited' => true,
            'source' => 'stream',
            'readAt' => '2026-10-07T12:01:00+00:00',
            'seenAt' => '2026-10-07T12:01:00+00:00',
        ], $rows[0]);
        self::assertSame(29.5, $rows[1]['usedPercent']);
        self::assertSame('week', $rows[1]['window']);
    }

    public function test_the_archive_entry_is_named_after_the_readings(): void
    {
        self::bootKernel();

        self::assertSame('bridge_account_readings.json', $this->exporter()->filename());
    }

    private function exporter(): BridgeAccountReadingExporter
    {
        $readings = self::getContainer()->get(BridgeAccountReadingRepository::class);
        self::assertInstanceOf(BridgeAccountReadingRepository::class, $readings);

        return new BridgeAccountReadingExporter($readings);
    }
}
