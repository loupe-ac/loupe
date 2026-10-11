<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Repository;

use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Entity\ArtifactFile;
use App\Module\Artifact\Repository\ArtifactBlobRepository;
use App\Module\Artifact\Repository\ArtifactRepository;
use App\Module\Artifact\Repository\ArtifactVersionRepository;
use App\Tests\Module\Artifact\ArtifactScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ArtifactRepositoryTest extends KernelTestCase
{
    use ArtifactScenario;

    public function test_a_version_keeps_its_files_and_numbers_count_up_from_one(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, 'artifact-versions@example.com', 'Artifact Versions');
        $blobs = static::getContainer()->get(ArtifactBlobRepository::class);

        $artifact = new Artifact($project, 'Widget tokens');
        $first = $artifact->addVersion(storedBySession: 'session-1');
        $first->addFile('index.html', $blobs->findOrCreate($project, '<p>v1</p>'), 'text/html');
        $second = $artifact->addVersion();
        $second->addFile('index.html', $blobs->findOrCreate($project, '<p>v2</p>'), 'text/html');
        $em->persist($artifact);
        $em->flush();
        $em->clear();

        $versions = static::getContainer()->get(ArtifactVersionRepository::class);
        $reloaded = $em->find(Artifact::class, $artifact->id);
        self::assertInstanceOf(Artifact::class, $reloaded);

        $latest = $versions->findLatest($reloaded);
        self::assertNotNull($latest);
        self::assertSame(2, $latest->number);
        $file = $latest->files->first();
        self::assertInstanceOf(ArtifactFile::class, $file);
        self::assertSame('<p>v2</p>', $file->blob->contents());
        self::assertSame('session-1', $versions->findByNumber($reloaded, 1)?->storedBySession);
        self::assertNull($versions->findByNumber($reloaded, 3));
    }

    public function test_archived_artifacts_leave_the_list_unless_asked_for(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, 'artifact-archived@example.com', 'Artifact Archived');
        $live = new Artifact($project, 'Live');
        $archived = new Artifact($project, 'Archived');
        $archived->archivedAt = new \DateTimeImmutable();
        $em->persist($live);
        $em->persist($archived);
        $em->flush();

        $repository = static::getContainer()->get(ArtifactRepository::class);

        self::assertSame([$live], $repository->findByProject($project));
        self::assertCount(2, $repository->findByProject($project, includeArchived: true));
    }
}
