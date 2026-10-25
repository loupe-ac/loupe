<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification;

use App\Module\Account\Entity\User;
use App\Module\Notification\Entity\PushDevice;
use App\Module\Notification\Service\PushAvailability;
use App\Tests\Support\AcceptedTerms;
use App\Tests\Support\FeatureFlags;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\VAPID;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Uid\Uuid;

/** Builders shared by the notification tests that need a database. */
trait NotificationScenario
{
    private const string ENDPOINT = 'https://web.push.apple.com/QGxyz';

    /**
     * A real P-256 public key and a 16 byte auth secret, both in the base64url form a browser sends.
     *
     * @return array{publicKey: string, authToken: string}
     */
    private static function subscriptionKeys(): array
    {
        return [
            'publicKey' => VAPID::createVapidKeys()['publicKey'],
            'authToken' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
        ];
    }

    /** @param non-empty-string $email */
    private function makeUser(ContainerInterface $container, string $email): User
    {
        $em = $container->get(EntityManagerInterface::class);
        $user = new User(fullName: 'Push Person', email: $email, password: 'hashed-password-placeholder');
        AcceptedTerms::stamp($user, $container);
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function makeDevice(ContainerInterface $container, User $owner, string $endpoint = self::ENDPOINT, string $hash = 'hash-of-token'): PushDevice
    {
        $keys = self::subscriptionKeys();
        $device = new PushDevice($owner, $endpoint, $keys['publicKey'], $keys['authToken'], 'aes128gcm', 'Chrome on macOS', $hash);
        $em = $container->get(EntityManagerInterface::class);
        $em->persist($device);
        $em->flush();

        return $device;
    }

    private static function idOf(PushDevice $device): Uuid
    {
        return $device->id ?? throw new \LogicException('a persisted device has an id');
    }

    private static function availability(bool $flag = true): PushAvailability
    {
        $keys = VAPID::createVapidKeys();

        return new PushAvailability(FeatureFlags::service([PushAvailability::FLAG => $flag]), $keys['publicKey'], $keys['privateKey']);
    }
}
