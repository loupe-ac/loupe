<?php

declare(strict_types=1);

namespace App\Module\Notification\Command;

use App\Exception\DomainErrors;
use App\Module\Notification\Messenger\DeliverWebPush;
use App\Module\Notification\Repository\PushDeviceRepository;
use App\Module\Notification\Service\PushAvailability;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Queues one declarative push to one device, so the user sees at once that the device works. */
final readonly class SendTestPushHandler
{
    /** The Declarative Web Push magic number. */
    private const int DECLARATIVE_MAGIC = 8030;

    private const int TTL_SECONDS = 300;

    public function __construct(
        private PushDeviceRepository $pushDevices,
        private PushAvailability $availability,
        private MessageBusInterface $bus,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private Auditor $auditor,

        #[Autowire(param: 'app.url')]
        private string $appUrl,
    ) {
    }

    public function __invoke(SendTestPushCommand $command): void
    {
        $device = $this->pushDevices->findOneOwnedBy($command->user, $command->deviceId)
            ?? throw new NotFoundHttpException('No such push device.');

        if (!$this->availability->isAvailable()) {
            throw new DomainErrors(['device' => 'notification.device.error.unavailable']);
        }

        if (null !== $device->stoppedAt) {
            throw new DomainErrors(['device' => 'notification.device.error.stopped']);
        }

        $base = rtrim($this->appUrl, '/');
        $url = $base.$this->urlGenerator->generate('app_account_notifications');
        $payload = json_encode([
            'web_push' => self::DECLARATIVE_MAGIC,
            'mutable' => true,
            'notification' => [
                'title' => $this->translator->trans('notification.push.test.title'),
                'body' => $this->translator->trans('notification.push.test.body'),
                'navigate' => $url,
                'tag' => 'test',
                'icon' => $base.'/icons/push-192.png',
                'data' => ['url' => $url, 'openTags' => ['test']],
            ],
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        $deviceId = $command->deviceId->toRfc4122();
        $this->bus->dispatch(new DeliverWebPush($deviceId, $payload, ttl: self::TTL_SECONDS));

        $this->auditor->record(
            'notification.test_push_queued',
            AuditOutcome::Success,
            ['deviceId' => $deviceId],
            new AuditSubject('push_device', $deviceId),
        );
    }
}
