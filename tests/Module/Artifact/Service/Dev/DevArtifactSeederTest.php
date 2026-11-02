<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Service\Dev;

use App\DevSeed\DevSeedId;
use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Repository\ArtifactRepository;
use App\Module\Artifact\Service\Dev\DevArtifactSeeder;
use App\Tests\DevSeed\DevProjectFixture;
use App\Tests\DevSeed\SeedContextFactory;
use App\Tests\Module\Artifact\ArtifactScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DevArtifactSeederTest extends KernelTestCase
{
    use ArtifactScenario;

    public function test_it_stores_one_prototype_with_two_versions_and_a_fixed_id(): void
    {
        self::bootKernel();
        $context = SeedContextFactory::create(self::getContainer());
        $project = DevProjectFixture::create(self::getContainer(), $context);

        self::getContainer()->get(DevArtifactSeeder::class)->seed($context);

        $this->em()->clear();
        $artifacts = self::getContainer()->get(ArtifactRepository::class)->findByProject($project);
        self::assertCount(1, $artifacts);
        self::assertEquals(DevSeedId::of('artifact/artifact/pricing-page-prototype'), $artifacts[0]->id);
        self::assertCount(2, $artifacts[0]->versions);
        $latest = $this->versionNumbered($artifacts[0], 2);
        self::assertSame(['app.js', 'index.html', 'plans.html', 'style.css'], array_map(static fn ($file): string => $file->path, $latest->files->toArray()));
        self::assertSame('index.html', $latest->entryFile()?->path);
        self::assertSame(DevArtifactSeeder::SOURCE_LINK, $artifacts[0]->sourceLink);
        self::assertNotNull($this->versionNumbered($artifacts[0], 1)->notes);
        self::assertNotNull($latest->notes);
        self::assertSame($context->at(120)->format('c'), $artifacts[0]->createdAt->format('c'));
    }

    public function test_a_second_run_writes_no_row(): void
    {
        self::bootKernel();
        $context = SeedContextFactory::create(self::getContainer());
        $project = DevProjectFixture::create(self::getContainer(), $context);
        $seeder = self::getContainer()->get(DevArtifactSeeder::class);

        $seeder->seed($context);
        $seeder->seed($context);

        $this->em()->clear();
        $artifacts = self::getContainer()->get(ArtifactRepository::class)->findByProject($project, includeArchived: true);
        self::assertCount(1, $artifacts);
        self::assertContainsOnlyInstancesOf(Artifact::class, $artifacts);
    }
}
