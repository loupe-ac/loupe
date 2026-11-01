<?php

declare(strict_types=1);

namespace App\Module\Notification\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Account\Entity\User;
use App\Module\Account\Service\Dev\DevAccountSeeder;
use App\Module\Notification\Entity\NotificationPreference;
use App\Module\Notification\Entity\PushDevice;
use App\Module\Notification\NotificationChannel;
use App\Module\Notification\NotificationEvent;
use App\Module\Notification\Repository\NotificationPreferenceRepository;
use App\Module\Notification\Repository\PushDeviceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * The notification settings page shows a grid of choices and a list of devices.
 * The dev user holds one explicit choice per event and two registered devices.
 * The endpoints point at a reserved host, so a push to them fails at the
 * network and reaches no real browser.
 */
#[When('dev')]
#[When('test')]
final readonly class DevNotificationSeeder implements DevSeederInterface
{
    public const string KEY = 'notifications';

    /** @var array<string, array{label: string, ago: int}> */
    private const array DEVICES = [
        'laptop' => ['label' => 'Chrome on macOS', 'ago' => 20 * 86_400],
        'workstation' => ['label' => 'Firefox on Linux', 'ago' => 3 * 86_400],
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private NotificationPreferenceRepository $notificationPreferences,
        private PushDeviceRepository $pushDevices,
    ) {
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [DevAccountSeeder::KEY];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        $user = $context->devUser();
        $this->preference($user, NotificationEvent::BlockingInboxItem, true);
        $this->preference($user, NotificationEvent::OtherInboxItem, false);

        foreach (self::DEVICES as $key => $device) {
            $this->device($context, $user, $key, $device['label'], $device['ago']);
        }
        $this->em->flush();
    }

    private function preference(User $user, NotificationEvent $event, bool $enabled): void
    {
        if ($this->notificationPreferences->findOneByCell($user, $event, NotificationChannel::WebPush) instanceof NotificationPreference) {
            return;
        }
        $this->em->persist(new NotificationPreference($user, $event, NotificationChannel::WebPush, $enabled));
    }

    private function device(SeedContext $context, User $user, string $key, string $label, int $secondsAgo): void
    {
        $id = DevSeedId::of('notification/push-device/'.$key);
        if ($this->pushDevices->find($id) instanceof PushDevice) {
            return;
        }

        $device = new PushDevice(
            owner: $user,
            endpoint: \sprintf('https://push.example.test/send/%s', $id->toRfc4122()),
            publicKey: base64_encode(hash('sha256', 'dev-seed-public-'.$key, true)),
            authToken: base64_encode(substr(hash('sha256', 'dev-seed-auth-'.$key, true), 0, 16)),
            contentEncoding: 'aes128gcm',
            label: $label,
            deviceTokenHash: hash('sha256', 'dev-seed-device-'.$key),
            createdAt: $context->at($secondsAgo),
        );
        $context->ids->assign($device, $id);
        $this->em->persist($device);
    }
}
