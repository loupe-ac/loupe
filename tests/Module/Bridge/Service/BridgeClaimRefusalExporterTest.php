<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Entity\BridgeClaimRefusal;
use App\Module\Bridge\Repository\BridgeClaimRefusalRepository;
use App\Module\Bridge\Service\BridgeClaimRefusalExporter;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BridgeClaimRefusalExporterTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_it_exports_the_refusals_of_the_account_alone(): void
    {
        self::bootKernel();
        $em = $this->em();
        $exporting = $this->user($em, 'refusals-export-mine@example.com');
        $other = $this->user($em, 'refusals-export-other@example.com');
        $project = $this->project($em, $exporting, 'Refusals export mine');
        $otherProject = $this->project($em, $other, 'Refusals export other');
        $bridge = $this->seedBridge($em, $exporting);
        $named = $this->seedWorkRequest($em, $project);
        $unnamed = $this->seedWorkRequest($em, $project, kind: 'fix');
        $em->persist(new BridgeClaimRefusal(Uuid::v7(), $bridge, $named, 'work'));
        $em->persist(new BridgeClaimRefusal(Uuid::v7(), $bridge, $unnamed, null));
        $em->persist(new BridgeClaimRefusal(Uuid::v7(), $this->seedBridge($em, $other), $this->seedWorkRequest($em, $otherProject), 'work'));
        $em->flush();
        $em->clear();

        $rows = iterator_to_array($this->exporter()->export($exporting), false);

        $expected = [
            ['bridgeId' => $bridge->id->toRfc4122(), 'workRequestId' => $named->id?->toRfc4122(), 'account' => 'work'],
            ['bridgeId' => $bridge->id->toRfc4122(), 'workRequestId' => $unnamed->id?->toRfc4122(), 'account' => null],
        ];
        usort($expected, static fn (array $a, array $b): int => $a['workRequestId'] <=> $b['workRequestId']);
        self::assertSame($expected, $rows);
    }

    public function test_the_archive_entry_is_named_after_the_refusals(): void
    {
        self::bootKernel();

        self::assertSame('bridge_claim_refusals.json', $this->exporter()->filename());
    }

    private function exporter(): BridgeClaimRefusalExporter
    {
        $refusals = self::getContainer()->get(BridgeClaimRefusalRepository::class);
        self::assertInstanceOf(BridgeClaimRefusalRepository::class, $refusals);

        return new BridgeClaimRefusalExporter($refusals);
    }
}
