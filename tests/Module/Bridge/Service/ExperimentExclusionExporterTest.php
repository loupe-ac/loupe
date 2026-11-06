<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Entity\ExperimentExclusion;
use App\Module\Bridge\Service\ExperimentExclusionExporter;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ExperimentExclusionExporterTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_it_exports_the_exclusions_of_the_projects_the_user_owns(): void
    {
        self::bootKernel();
        $em = $this->em();
        $exporting = $this->user($em, 'exclusions-export-mine@example.com');
        $other = $this->user($em, 'exclusions-export-other@example.com');
        $cardId = Uuid::v7();
        $em->persist(new ExperimentExclusion(
            project: $this->project($em, $exporting, 'Excluded Project'),
            cardId: $cardId,
            experiment: 'php-lsp',
            reason: 'Changes no PHP',
            excludedBy: $exporting,
            excludedAt: new \DateTimeImmutable('2026-09-13T10:00:00+00:00'),
        ));
        $em->persist(new ExperimentExclusion($this->project($em, $other, 'Other Project'), Uuid::v7(), 'php-lsp', 'Other', $other, new \DateTimeImmutable()));
        $em->flush();
        $em->clear();

        $rows = iterator_to_array($this->exporter()->export($exporting), false);

        self::assertSame([[
            'project' => 'Excluded Project',
            'cardId' => (string) $cardId,
            'experiment' => 'php-lsp',
            'reason' => 'Changes no PHP',
            'excludedAt' => '2026-09-13T10:00:00+00:00',
        ]], $rows);
    }

    public function test_the_file_is_named_for_the_exclusions(): void
    {
        self::bootKernel();

        self::assertSame('experiment_exclusions.json', $this->exporter()->filename());
    }

    private function exporter(): ExperimentExclusionExporter
    {
        $exporter = self::getContainer()->get(ExperimentExclusionExporter::class);
        self::assertInstanceOf(ExperimentExclusionExporter::class, $exporter);

        return $exporter;
    }
}
