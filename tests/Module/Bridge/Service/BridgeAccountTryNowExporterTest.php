<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Entity\BridgeAccountTryNow;
use App\Module\Bridge\Repository\BridgeAccountTryNowRepository;
use App\Module\Bridge\Service\BridgeAccountTryNowExporter;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BridgeAccountTryNowExporterTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_it_exports_the_overrides_of_the_account_alone(): void
    {
        self::bootKernel();
        $em = $this->em();
        $exporting = $this->user($em, 'try-now-export-mine@example.com');
        $shared = Uuid::v4();
        $utc = new \DateTimeZone('UTC');
        $mine = new BridgeAccountTryNow(Uuid::v7(), $this->seedBridge($em, $exporting, $shared), 'work', new \DateTimeImmutable('2026-10-07 12:00:00', $utc));
        $mine->closedAt = new \DateTimeImmutable('2026-10-07 12:05:00', $utc);
        $mine->checkedAt = new \DateTimeImmutable('2026-10-07 12:04:00', $utc);
        $mine->stillLimited = true;
        $em->persist($mine);
        $em->persist(new BridgeAccountTryNow(Uuid::v7(), $this->seedBridge($em, $this->user($em, 'try-now-export-other@example.com'), $shared), 'work', new \DateTimeImmutable('2026-10-07 12:00:00', $utc)));
        $em->flush();
        $em->clear();

        $rows = iterator_to_array($this->exporter()->export($exporting), false);

        self::assertSame([[
            'bridgeId' => $shared->toRfc4122(),
            'account' => 'work',
            'pressedAt' => '2026-10-07T12:00:00+00:00',
            'closedAt' => '2026-10-07T12:05:00+00:00',
            'checkedAt' => '2026-10-07T12:04:00+00:00',
            'stillLimited' => true,
        ]], $rows);
    }

    public function test_the_archive_entry_is_named_after_the_overrides(): void
    {
        self::bootKernel();

        self::assertSame('bridge_account_try_now.json', $this->exporter()->filename());
    }

    private function exporter(): BridgeAccountTryNowExporter
    {
        $overrides = self::getContainer()->get(BridgeAccountTryNowRepository::class);
        self::assertInstanceOf(BridgeAccountTryNowRepository::class, $overrides);

        return new BridgeAccountTryNowExporter($overrides);
    }
}
