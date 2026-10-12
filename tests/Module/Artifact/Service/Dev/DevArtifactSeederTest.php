<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Service\Dev;

use App\Module\Artifact\Install\ArtifactInstallFlags;
use App\Module\Artifact\Repository\ArtifactBlobRepository;
use App\Module\Artifact\Repository\ArtifactRepository;
use App\Module\Artifact\Service\Dev\DevArtifactSeeder;
use App\Tests\Module\Artifact\ArtifactScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DevArtifactSeederTest extends KernelTestCase
{
    use ArtifactScenario;

    public function test_it_stores_one_prototype_with_two_versions_once_and_switches_the_flag_on(): void
    {
        self::bootKernel();
        $project = $this->project($this->em(), 'dev-artifact-seed@example.com', 'Seeded');
        $seeder = new DevArtifactSeeder(
            $this->em(),
            self::getContainer()->get(ArtifactRepository::class),
            self::getContainer()->get(ArtifactBlobRepository::class),
        );

        self::assertTrue($seeder->seed($project));
        self::assertFalse($seeder->seed($project));

        $this->em()->clear();
        $artifacts = self::getContainer()->get(ArtifactRepository::class)->findByProject($project);
        self::assertCount(1, $artifacts);
        self::assertCount(2, $artifacts[0]->versions);
        $latest = $this->versionNumbered($artifacts[0], 2);
        self::assertSame(['app.js', 'index.html', 'plans.html', 'style.css'], array_map(static fn ($file): string => $file->path, $latest->files->toArray()));
        self::assertSame('index.html', $latest->entryFile()?->path);
        self::assertSame(DevArtifactSeeder::SOURCE_LINK, $artifacts[0]->sourceLink);
        self::assertNotNull($this->versionNumbered($artifacts[0], 1)->notes);
        self::assertNotNull($latest->notes);
        self::assertSame('true', $this->em()->getConnection()->fetchOne('SELECT value FROM feature_flag WHERE name = ?', [ArtifactInstallFlags::FLAG_ENABLED]));
    }
}
