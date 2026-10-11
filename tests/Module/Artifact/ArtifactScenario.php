<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact;

use App\Module\Account\Entity\User;
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
}
