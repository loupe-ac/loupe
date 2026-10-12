<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact;

use App\Module\Account\Entity\User;
use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Entity\ArtifactVersion;
use App\Module\Artifact\Install\ArtifactInstallFlags;
use App\Module\Artifact\Repository\ArtifactBlobRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Support\AcceptedTerms;
use App\Tests\Support\AgentCredential;
use Doctrine\ORM\EntityManagerInterface;

/** Fixtures the Artifact module's database tests share. */
trait ArtifactScenario
{
    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /** @param non-empty-string $email */
    private function project(EntityManagerInterface $em, string $email, string $name): Project
    {
        $user = new User(fullName: 'Riley Chen', email: $email, password: 'hashed');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, static::getContainer());
        $em->persist($user);
        $em->flush();

        $project = new Project(AgentCredential::managed($em, $user, $user->id), $name);
        $em->persist($project);
        $em->flush();

        return $project;
    }

    /** @param non-empty-string $email */
    private function switchedOnProject(string $email, string $name = 'Artifacts'): Project
    {
        $em = $this->em();
        $project = $this->project($em, $email, $name);
        $em->getConnection()->executeStatement("UPDATE feature_flag SET value = 'true' WHERE name = ?", [ArtifactInstallFlags::FLAG_ENABLED]);

        return $project;
    }

    /**
     * One version for each entry of $versions, each entry a map of path to contents.
     *
     * @param list<array<string, string>> $versions
     */
    private function storedArtifact(Project $project, string $name, array $versions, ?User $storedBy = null, ?string $session = null, ?string $sourceLink = null): Artifact
    {
        $em = $this->em();
        $blobs = static::getContainer()->get(ArtifactBlobRepository::class);
        self::assertInstanceOf(ArtifactBlobRepository::class, $blobs);

        $artifact = new Artifact($project, $name, $storedBy, $sourceLink);
        foreach ($versions as $files) {
            $version = $artifact->addVersion($storedBy, $session);
            foreach ($files as $path => $contents) {
                $version->addFile($path, $blobs->findOrCreate($project, $contents), str_ends_with($path, '.html') ? 'text/html' : 'text/plain');
            }
        }
        $em->persist($artifact);
        $em->flush();

        return $artifact;
    }

    private function versionNumbered(Artifact $artifact, int $number): ArtifactVersion
    {
        foreach ($artifact->versions as $version) {
            if ($version->number === $number) {
                return $version;
            }
        }

        throw new \LogicException(\sprintf('The artifact has no version %d.', $number));
    }
}
