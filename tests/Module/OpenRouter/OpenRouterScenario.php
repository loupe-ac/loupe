<?php

declare(strict_types=1);

namespace App\Tests\Module\OpenRouter;

use App\Module\Account\Entity\User;
use App\Module\OpenRouter\Entity\OpenRouterKey;
use App\Module\OpenRouter\Repository\OpenRouterKeyRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Builders shared by the OpenRouter tests that need a database. */
trait OpenRouterScenario
{
    private function keys(): OpenRouterKeyRepository
    {
        $keys = static::getContainer()->get(OpenRouterKeyRepository::class);
        \assert($keys instanceof OpenRouterKeyRepository);

        return $keys;
    }

    private function makeUser(ContainerInterface $container, string $label): User
    {
        $em = $container->get(EntityManagerInterface::class);
        $user = new User(fullName: 'Key Owner', email: $label.'-'.bin2hex(random_bytes(4)).'@example.com', password: 'hashed-password-placeholder');
        AcceptedTerms::stamp($user, $container);
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function makeProject(ContainerInterface $container, User $owner): Project
    {
        $em = $container->get(EntityManagerInterface::class);
        $project = new Project($owner, 'OpenRouter '.bin2hex(random_bytes(4)));
        $em->persist($project);
        $em->flush();

        return $project;
    }

    private function makeKey(ContainerInterface $container, User|Project $owner, string $secret = 'sk-or-v1-0000000000000000a3f9'): OpenRouterKey
    {
        $em = $container->get(EntityManagerInterface::class);
        $key = $owner instanceof User
            ? new OpenRouterKey($secret, user: $owner)
            : new OpenRouterKey($secret, project: $owner);
        $em->persist($key);
        $em->flush();

        return $key;
    }
}
