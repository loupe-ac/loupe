<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Service;

use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Install\ArtifactInstallFlags;
use App\Module\Artifact\Service\ArtifactProjectStatsProvider;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Artifact\ArtifactScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ArtifactProjectStatsProviderTest extends KernelTestCase
{
    use ArtifactScenario;

    public function test_it_counts_the_artifacts_of_each_project_and_skips_archived_ones(): void
    {
        self::bootKernel();
        $this->switchLibrary('true');
        $busy = $this->project($this->em(), 'artifact-stats-busy@example.com', 'Busy');
        $quiet = $this->project($this->em(), 'artifact-stats-quiet@example.com', 'Quiet');
        $this->artifact($busy, 'One');
        $this->artifact($busy, 'Two');
        $this->artifact($busy, 'Gone')->archivedAt = new \DateTimeImmutable();
        $this->em()->flush();

        $stats = $this->provider()->statsFor([$busy, $quiet]);

        self::assertSame(2, $stats[(string) $busy->id]->artifactCount);
        self::assertArrayNotHasKey((string) $quiet->id, $stats);
    }

    public function test_it_counts_nothing_while_the_library_is_off(): void
    {
        self::bootKernel();
        $this->switchLibrary('false');
        $project = $this->project($this->em(), 'artifact-stats-off@example.com', 'Off');
        $this->artifact($project, 'One');
        // Guard: the empty result below also passes when no artifact was stored.
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM artifacts WHERE project_id = ?', [(string) $project->id]));

        self::assertSame([], $this->provider()->statsFor([$project]));
    }

    private function provider(): ArtifactProjectStatsProvider
    {
        $provider = self::getContainer()->get(ArtifactProjectStatsProvider::class);
        self::assertInstanceOf(ArtifactProjectStatsProvider::class, $provider);

        return $provider;
    }

    private function switchLibrary(string $value): void
    {
        $this->em()->getConnection()->executeStatement("UPDATE feature_flag SET value = '".$value."' WHERE name = ?", [ArtifactInstallFlags::FLAG_ENABLED]);
    }

    private function artifact(Project $project, string $name): Artifact
    {
        $artifact = new Artifact($project, $name, $project->owner);
        $this->em()->persist($artifact);
        $this->em()->flush();

        return $artifact;
    }
}
