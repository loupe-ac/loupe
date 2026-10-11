<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\EventListener;

use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\EventListener\DeleteArtifactsOnProjectDeleting;
use App\Module\Artifact\Repository\ArtifactBlobRepository;
use App\Module\Project\Entity\Project;
use App\Module\Project\Event\ProjectDeleting;
use App\Tests\Module\Artifact\ArtifactScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DeleteArtifactsOnProjectDeletingTest extends KernelTestCase
{
    use ArtifactScenario;

    public function test_the_listener_deletes_the_artifacts_of_its_project_only(): void
    {
        self::bootKernel();
        $doomed = $this->project($this->em(), 'artifact-listener-doomed@example.com', 'Doomed');
        $spared = $this->project($this->em(), 'artifact-listener-spared@example.com', 'Spared');
        $this->artifact($doomed);
        $this->artifact($spared);
        $connection = $this->em()->getConnection();
        // Guard: the counts below also pass when no fixture wrote a row.
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM artifact_files'));

        $listener = self::getContainer()->get(DeleteArtifactsOnProjectDeleting::class);
        self::assertInstanceOf(DeleteArtifactsOnProjectDeleting::class, $listener);
        $listener(new ProjectDeleting($doomed));

        foreach (['artifact_files', 'artifact_versions', 'artifacts', 'artifact_blobs'] as $table) {
            self::assertSame(1, (int) $connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s', $table)), $table);
        }
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM artifacts WHERE project_id = ?', [(string) $spared->id]));
    }

    private function artifact(Project $project): void
    {
        $em = $this->em();
        $artifact = new Artifact($project, 'Prototype', $project->owner);
        $version = $artifact->addVersion($project->owner);
        $blobs = self::getContainer()->get(ArtifactBlobRepository::class);
        self::assertInstanceOf(ArtifactBlobRepository::class, $blobs);
        $version->addFile('index.html', $blobs->findOrCreate($project, 'hello '.$project->id), 'text/html');
        $em->persist($artifact);
        $em->persist($version);
        $em->flush();
    }
}
