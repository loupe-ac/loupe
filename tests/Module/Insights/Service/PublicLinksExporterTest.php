<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Service;

use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Service\PublicLinksExporter;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PublicLinksExporterTest extends KernelTestCase
{
    use InsightsScenario;

    public function test_it_exports_each_link_of_the_projects_the_user_owns(): void
    {
        self::bootKernel();
        $project = $this->scenarioProject('links-export');
        $on = PublicLink::forGraph($this->seedSavedGraph($project, 'On', 0));
        $off = PublicLink::forGraph($this->seedSavedGraph($project, 'Off', 1));
        $off->enabled = false;
        $experiment = PublicLink::forExperiment($project, 'php-lsp', "What we **tested**\nand learned");
        foreach ([$on, $off, $experiment] as $link) {
            $this->em()->persist($link);
        }
        $other = PublicLink::forGraph($this->seedSavedGraph($this->scenarioProject('links-export-other'), 'Other', 0));
        $this->em()->persist($other);
        $this->em()->flush();
        $this->em()->clear();

        $exporter = self::getContainer()->get(PublicLinksExporter::class);
        self::assertInstanceOf(PublicLinksExporter::class, $exporter);
        $rows = iterator_to_array($exporter->export($project->owner), false);

        self::assertSame('insights_public_links.json', $exporter->filename());
        $byCode = [];
        foreach ($rows as $row) {
            $byCode[$row['code']] = $row;
        }
        self::assertCount(3, $rows);
        self::assertSame(['projectId' => (string) $project->id, 'project' => $project->name, 'kind' => 'graph', 'item' => 'On', 'code' => $on->code, 'enabled' => true, 'note' => null], $byCode[$on->code]);
        self::assertSame('Off', $byCode[$off->code]['item']);
        self::assertFalse($byCode[$off->code]['enabled']);
        self::assertSame(['projectId' => (string) $project->id, 'project' => $project->name, 'kind' => 'experiment', 'item' => 'php-lsp', 'code' => $experiment->code, 'enabled' => true, 'note' => "What we **tested**\nand learned"], $byCode[$experiment->code]);
        self::assertArrayNotHasKey($other->code, $byCode);
    }
}
