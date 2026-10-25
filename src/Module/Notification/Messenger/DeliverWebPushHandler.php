<?php

declare(strict_types=1);

namespace App\Module\Notification\Messenger;

use App\Module\Notification\Entity\PushDevice;
use App\Module\Notification\Repository\PushDeviceRepository;
use App\Module\Notification\Service\DeviceChangedPublisher;
use App\Module\Notification\Service\PushAvailability;
use App\Module\Notification\Service\PushEndpointPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsMessageHandler]
final readonly class DeliverWebPushHandler
{
    /** The content type that marks a push as declarative. The library sends application/octet-stream otherwise. */
    private const string CONTENT_TYPE = 'application/notification+json';

    public function __construct(
        private PushDeviceRepository $pushDevices,
        private PushAvailability $availability,
        private PushEndpointPolicy $endpointPolicy,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private ClockInterface $clock,
        private DeviceChangedPublisher $devicesChanged,

        #[Autowire(service: 'web_push.client')]
        private HttpClientInterface $httpClient,

        #[Autowire(param: 'app.url')]
        private string $subject,
    ) {
    }

    public function __invoke(DeliverWebPush $message): void
    {
        $device = $this->pushDevices->find($message->deviceId);
        if (!$device instanceof PushDevice || null !== $device->stoppedAt) {
            return;
        }

        $keys = $this->availability->keys();
        if (!$this->availability->isAvailable() || null === $keys) {
            $this->logger->info('notification.push_skipped', ['deviceId' => $message->deviceId, 'reason' => 'push_unavailable']);

            return;
        }

        if (!$this->endpointPolicy->accepts($device->endpoint)) {
            $this->logger->warning('notification.push_skipped', ['deviceId' => $message->deviceId, 'reason' => 'endpoint_refused']);

            return;
        }

        $psr18 = new Psr18Client($this->httpClient);
        $webPush = new WebPush(
            ['VAPID' => ['subject' => $this->subject, 'publicKey' => $keys->publicKey, 'privateKey' => $keys->privateKey]],
            ['contentType' => self::CONTENT_TYPE, 'TTL' => $message->ttl, 'urgency' => $message->urgency],
            $psr18,
            $psr18,
            $psr18,
        );

        try {
            $report = $webPush->sendOneNotification(
                new Subscription($device->endpoint, $device->publicKey, $device->authToken, $device->contentEncoding),
                $message->payload,
            );
        } catch (\Exception|\ValueError|\TypeError $e) {
            $this->logger->warning('notification.push_dropped', ['deviceId' => $message->deviceId, 'reason' => 'unsendable', 'error' => $e->getMessage()]);

            return;
        }

        $this->settle($device, $report);
    }

    private function settle(PushDevice $device, MessageSentReport $report): void
    {
        $context = ['deviceId' => (string) $device->id];
        $status = $report->getResponse()?->getStatusCode();

        if ($report->isSubscriptionExpired()) {
            $device->stoppedAt = $this->clock->now();
            $this->em->flush();
            $this->devicesChanged->changed($device->owner);
            $this->logger->info('notification.push_device_stopped', $context + ['status' => $status]);

            return;
        }

        if (null === $status || 429 === $status || $status >= 500) {
            $this->logger->warning('notification.push_retried', $context + ['status' => $status, 'reason' => $report->getReason()]);

            // forceRetry false keeps the retry budget of the transport.
            throw new RecoverableMessageHandlingException($report->getReason(), 0, null, forceRetry: false);
        }

        if ($status < 200 || $status >= 300) {
            $this->logger->warning('notification.push_dropped', $context + ['status' => $status, 'reason' => $report->getReason()]);

            return;
        }

        $device->lastDeliveredAt = $this->clock->now();
        $this->em->flush();
        $this->logger->info('notification.push_delivered', $context + ['status' => $status]);
    }
}
