<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Service;

use App\Module\Account\Deletion\AccountPurger;
use App\Module\Account\Entity\User;
use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Repository\ArtifactBlobRepository;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardArtifact;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Artifact\ArtifactScenario;
use App\Tests\Module\Board\BoardColumnFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ArtifactAccountPurgerTest extends KernelTestCase
{
    use ArtifactScenario;
    use BoardColumnFixtures;

    public function test_deleting_an_account_removes_the_artifacts_of_its_projects(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, 'artifact-purge-owner@example.com', 'Doomed');
        $artifact = $this->artifact($project, $project->owner);
        $this->seedColumns($project);
        $card = new Card(project: $project, column: $this->column($project, 'backlog'), title: 'Ship it', body: 'Body', number: 1);
        $em->persist($card);
        $em->persist(new CardArtifact($card, $artifact));
        $em->flush();
        $owner = $project->owner;
        $connection = $em->getConnection();
        // Guard: the zero counts below also pass when no fixture wrote a row.
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM board_card_artifacts'));

        $purger = self::getContainer()->get(AccountPurger::class);
        self::assertInstanceOf(AccountPurger::class, $purger);
        $purger->purge($owner);

        foreach (['board_card_artifacts', 'artifact_files', 'artifact_versions', 'artifacts', 'artifact_blobs'] as $table) {
            self::assertSame(0, (int) $connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s', $table)), $table);
        }
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM users WHERE id = ?', [(string) $owner->id]));
    }

    public function test_an_artifact_stored_in_another_project_stays_and_loses_the_account_link(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, 'artifact-purge-host@example.com', 'Host');
        $guest = new User(fullName: 'Sam Guest', email: 'artifact-purge-guest@example.com', password: 'hashed');
        $em->persist($guest);
        $em->flush();
        $this->artifact($project, $guest);
        $connection = $em->getConnection();
        // Guard: the null checks below also pass when no fixture wrote a row.
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM artifacts WHERE created_by_id = ?', [(string) $guest->id]));

        $purger = self::getContainer()->get(AccountPurger::class);
        self::assertInstanceOf(AccountPurger::class, $purger);
        $purger->purge($guest);

        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM artifacts WHERE project_id = ? AND created_by_id IS NULL', [(string) $project->id]));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM artifact_versions WHERE stored_by_id IS NULL'));
    }

    private function artifact(Project $project, User $storedBy): Artifact
    {
        $em = $this->em();
        $artifact = new Artifact($project, 'Prototype', $storedBy);
        $version = $artifact->addVersion($storedBy);
        $blobs = self::getContainer()->get(ArtifactBlobRepository::class);
        self::assertInstanceOf(ArtifactBlobRepository::class, $blobs);
        $version->addFile('index.html', $blobs->findOrCreate($project, 'hello '.$project->id), 'text/html');
        $em->persist($artifact);
        $em->persist($version);
        $em->flush();

        return $artifact;
    }
}
