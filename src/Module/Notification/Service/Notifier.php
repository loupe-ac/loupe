<?php

declare(strict_types=1);

namespace App\Module\Notification\Service;

use App\Module\Account\Entity\User;
use App\Module\Notification\Messenger\DeliverWebPush;
use App\Module\Notification\NotificationChannel;
use App\Module\Notification\NotificationEvent;
use App\Module\Notification\PushContent;
use App\Module\Notification\Repository\PushDeviceRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/** Tells a user about an event. It sends nothing when push is off, unwanted or pointless because the user is looking at Loupe. */
final readonly class Notifier
{
    private const int TTL_SECONDS = 604800;

    public function __construct(
        private PushAvailability $availability,
        private NotificationPreferences $preferences,
        private PresenceStore $presence,
        private PushDeviceRepository $pushDevices,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function notify(User $user, NotificationEvent $event, PushContent $content): void
    {
        $userId = (string) $user->id;
        if (!$this->availability->isAvailable()) {
            $this->logger->info('notification.push_skipped', ['userId' => $userId, 'event' => $event->value, 'reason' => 'push_unavailable']);

            return;
        }

        if (!$this->preferences->isEnabled($user, $event, NotificationChannel::WebPush)) {
            $this->logger->info('notification.push_skipped', ['userId' => $userId, 'event' => $event->value, 'reason' => 'preference_off']);

            return;
        }

        if ($this->presence->isPresent($user)) {
            $this->logger->info('notification.push_skipped', ['userId' => $userId, 'event' => $event->value, 'reason' => 'owner_present']);

            return;
        }

        $payload = $content->toJson();
        $urgency = match ($event) {
            NotificationEvent::BlockingInboxItem => 'high',
            NotificationEvent::OtherInboxItem => 'normal',
        };
        $devices = $this->pushDevices->findActiveByOwner($user);
        foreach ($devices as $device) {
            $this->bus->dispatch(new DeliverWebPush(
                ($device->id ?? throw new \LogicException('A stored device has an id.'))->toRfc4122(),
                $payload,
                $urgency,
                self::TTL_SECONDS,
            ));
        }

        $this->logger->info('notification.push_queued', ['userId' => $userId, 'event' => $event->value, 'devices' => \count($devices)]);
    }
}
