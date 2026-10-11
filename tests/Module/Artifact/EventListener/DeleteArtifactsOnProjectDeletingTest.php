<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\EventListener;

use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\EventListener\DeleteArtifactsOnProjectDeleting;
use App\Module\Artifact\Repository\ArtifactBlobRepository;
use App\Module\Artifact\Repository\ArtifactRepository;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardArtifact;
use App\Module\Project\Entity\Project;
use App\Module\Project\Event\ProjectDeleting;
use App\Module\Project\Service\ProjectDeleter;
use App\Tests\Module\Artifact\ArtifactScenario;
use App\Tests\Module\Board\BoardColumnFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DeleteArtifactsOnProjectDeletingTest extends KernelTestCase
{
    use ArtifactScenario;
    use BoardColumnFixtures;

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

    public function test_deleting_a_project_removes_an_artifact_that_a_card_links(): void
    {
        self::bootKernel();
        $em = $this->em();
        $doomed = $this->project($em, 'artifact-linked-doomed@example.com', 'Doomed');
        $this->artifact($doomed);
        $this->seedColumns($doomed);
        $card = new Card(project: $doomed, column: $this->column($doomed, 'backlog'), title: 'Ship it', body: 'Body', number: 1);
        $em->persist($card);
        $artifacts = self::getContainer()->get(ArtifactRepository::class);
        self::assertInstanceOf(ArtifactRepository::class, $artifacts);
        $artifact = $artifacts->findOneBy(['project' => $doomed]);
        self::assertInstanceOf(Artifact::class, $artifact);
        $em->persist(new CardArtifact($card, $artifact));
        $em->flush();
        $connection = $em->getConnection();
        // Guard: the zero counts below also pass when no link was stored.
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM board_card_artifacts'));

        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        $deleter->delete($doomed);

        foreach (['board_card_artifacts', 'artifact_files', 'artifact_versions', 'artifacts', 'artifact_blobs'] as $table) {
            self::assertSame(0, (int) $connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s', $table)), $table);
        }
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
