<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Service;

use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Service\CancelledDiscoveryRunExporter;
use App\Tests\Module\Readiness\ReadinessScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CancelledDiscoveryRunExporterTest extends KernelTestCase
{
    use ReadinessScenario;

    public function test_it_exports_only_the_runs_that_the_user_cancelled(): void
    {
        self::bootKernel();
        $exporting = $this->user('discovery-export-mine@example.com');
        $other = $this->user('discovery-export-other@example.com');
        // The run of another person's project is the case: a member cancels it.
        $project = $this->project($other, 'Shared Work');
        $mine = new DiscoveryRun($project, new \DateTimeImmutable('2026-11-01T10:00:00+00:00'));
        $mine->cancelledBy = $exporting;
        $mine->fail('cancelled', new \DateTimeImmutable('2026-11-01T10:05:00+00:00'));
        $theirs = new DiscoveryRun($project, new \DateTimeImmutable('2026-11-02T10:00:00+00:00'));
        $theirs->cancelledBy = $other;
        $theirs->fail('cancelled', new \DateTimeImmutable('2026-11-02T10:05:00+00:00'));
        $uncancelled = new DiscoveryRun($this->project($exporting, 'Own Work'));
        foreach ([$mine, $theirs, $uncancelled] as $run) {
            $this->em()->persist($run);
        }
        $this->em()->flush();
        $this->em()->clear();

        $exporter = static::getContainer()->get(CancelledDiscoveryRunExporter::class);
        self::assertInstanceOf(CancelledDiscoveryRunExporter::class, $exporter);

        self::assertSame('cancelled_discovery_runs.json', $exporter->filename());
        self::assertSame([
            [
                'runId' => (string) $mine->id,
                'project' => 'Shared Work',
                'requestedAt' => '2026-11-01T10:00:00+00:00',
                'cancelledAt' => '2026-11-01T10:05:00+00:00',
            ],
        ], iterator_to_array($exporter->export($exporting), false));
    }
}
