<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Service;

use App\Module\Insights\Service\InsightsSavedGraphsExporter;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InsightsSavedGraphsExporterTest extends KernelTestCase
{
    use InsightsScenario;

    public function test_it_exports_the_active_graphs_of_the_projects_the_user_owns_in_order(): void
    {
        self::bootKernel();
        $project = $this->scenarioProject('graphs-export');
        $this->seedSavedGraph($project, 'Second', 5);
        $this->seedSavedGraph($project, 'First', 2);
        $this->seedSavedGraph($project, 'Removed', 3, new \DateTimeImmutable('2026-10-01 10:00:00'));
        $this->seedSavedGraph($this->scenarioProject('graphs-export-other'), 'Other', 0);

        $exporter = self::getContainer()->get(InsightsSavedGraphsExporter::class);
        self::assertInstanceOf(InsightsSavedGraphsExporter::class, $exporter);
        $rows = iterator_to_array($exporter->export($project->owner), false);

        self::assertSame('insights_saved_graphs.json', $exporter->filename());
        $row = static fn (int $position, string $name): array => [
            'projectId' => (string) $project->id,
            'project' => $project->name,
            'position' => $position,
            'name' => $name,
            'metric' => 'bucket-time:tests',
            'unit' => 'run',
            'statistic' => 'median',
            'group' => 'none',
            'range' => 'thirty-days',
            'bucket' => 'day',
            'compare' => 'off',
            'chartType' => 'line',
            'width' => 'half',
            'showLegend' => true,
        ];
        self::assertSame([$row(2, 'First'), $row(5, 'Second')], $rows);
    }
}
