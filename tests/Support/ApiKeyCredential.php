<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Account\Entity\User;
use App\Module\OAuth\Entity\ApiKey;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** A live API key for a test that calls a public /api/v1 route over HTTP. */
final readonly class ApiKeyCredential
{
    /** @return non-empty-string the key, as a client sends it */
    public static function mint(ContainerInterface $container, User $owner): string
    {
        $em = $container->get(EntityManagerInterface::class);
        if (!$em instanceof EntityManagerInterface) {
            throw new \LogicException('the entity manager is missing.');
        }

        $secret = ApiKey::PREFIX.bin2hex(random_bytes(32));
        $em->persist(new ApiKey($owner, 'Test key', ApiKey::hashOf($secret), ApiKey::displayStartOf($secret)));
        $em->flush();

        return $secret;
    }
}
