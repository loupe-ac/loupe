<?php

declare(strict_types=1);

namespace App\Module\Notification\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Notification\Entity\PushDevice;
use App\Module\Notification\Repository\PushDeviceRepository;
use App\Module\Notification\Service\DeviceChangedPublisher;
use App\Module\Notification\Service\DeviceLabel;
use App\Module\Notification\Service\PushAvailability;
use App\Module\Notification\Service\PushEndpointPolicy;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Stores the push subscription of one browser. A browser that comes back keeps
 * its row, found by its cookie, so a re-subscribe never adds a second line to
 * the list.
 */
final readonly class RegisterPushDeviceHandler
{
    private const array CONTENT_ENCODINGS = ['aes128gcm', 'aesgcm'];

    public function __construct(
        private PushDeviceRepository $pushDevices,
        private PushAvailability $availability,
        private PushEndpointPolicy $endpointPolicy,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private DeviceChangedPublisher $devicesChanged,
    ) {
    }

    public function __invoke(RegisterPushDeviceCommand $command): PushDevice
    {
        $this->assertAcceptable($command);

        $byEndpoint = $this->pushDevices->findOneByEndpoint($command->endpoint);
        if (null !== $byEndpoint && !$this->sameUser($byEndpoint->owner, $command->user)) {
            throw new DomainErrors(['endpoint' => 'notification.device.error.endpoint_taken']);
        }

        if (null !== $byEndpoint?->stoppedAt) {
            // The push service refused this endpoint, so only a new subscription can bring the device back.
            throw new DomainErrors(['endpoint' => 'notification.device.error.endpoint_stopped']);
        }

        $byCookie = '' === $command->deviceTokenHash ? null : $this->pushDevices->findOneByOwnerAndTokenHash($command->user, $command->deviceTokenHash);
        $device = $byCookie ?? $byEndpoint;
        $label = DeviceLabel::normalize($command->label);

        if (null !== $byCookie && null !== $byEndpoint && $byCookie !== $byEndpoint) {
            // The endpoint column is unique, so the old line leaves before the cookie line takes its address.
            $this->em->remove($byEndpoint);
            $this->em->flush();
        }

        $reused = null !== $device;
        if (null === $device) {
            $device = new PushDevice(
                $command->user,
                $command->endpoint,
                $command->publicKey,
                $command->authToken,
                $command->contentEncoding,
                $label ?? DeviceLabel::fromUserAgent($command->userAgent),
                $command->deviceTokenHash,
            );
            $this->em->persist($device);
        } else {
            $device->endpoint = $command->endpoint;
            $device->publicKey = $command->publicKey;
            $device->authToken = $command->authToken;
            $device->contentEncoding = $command->contentEncoding;
            $device->deviceTokenHash = $command->deviceTokenHash;
            $device->label = $label ?? $device->label;
            $device->stoppedAt = null;
            $device->warningDismissedAt = null;
        }

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // A parallel request stored this endpoint between the read above and the flush.
            throw new DomainErrors(['endpoint' => 'notification.device.error.endpoint_taken']);
        }

        $deviceId = (string) $device->id;
        $this->auditor->record(
            'notification.device_registered',
            AuditOutcome::Success,
            ['deviceId' => $deviceId, 'reused' => $reused],
            new AuditSubject('push_device', $deviceId),
        );
        $this->devicesChanged->changed($command->user);

        return $device;
    }

    private function assertAcceptable(RegisterPushDeviceCommand $command): void
    {
        if (!$this->availability->isAvailable()) {
            throw new DomainErrors(['endpoint' => 'notification.device.error.unavailable']);
        }

        $errors = [];
        foreach (['endpoint' => $command->endpoint, 'publicKey' => $command->publicKey, 'authToken' => $command->authToken] as $field => $value) {
            if ('' === trim($value)) {
                $errors[$field] = 'notification.device.error.required';
            }
        }

        if (!isset($errors['endpoint']) && !$this->endpointPolicy->accepts($command->endpoint)) {
            $errors['endpoint'] = 'notification.device.error.endpoint_refused';
        }

        if (!isset($errors['publicKey']) && 65 !== \strlen(self::decode($command->publicKey))) {
            $errors['publicKey'] = 'notification.device.error.key_invalid';
        }

        if (!isset($errors['authToken']) && 16 !== \strlen(self::decode($command->authToken))) {
            $errors['authToken'] = 'notification.device.error.key_invalid';
        }

        if (!\in_array($command->contentEncoding, self::CONTENT_ENCODINGS, true)) {
            $errors['contentEncoding'] = 'notification.device.error.encoding_invalid';
        }

        if ([] !== $errors) {
            throw new DomainErrors($errors);
        }
    }

    private function sameUser(User $a, User $b): bool
    {
        return null !== $a->id && $a->id->equals($b->id);
    }

    private static function decode(string $base64Url): string
    {
        $binary = base64_decode(strtr($base64Url, '-_', '+/'), true);

        return false === $binary ? '' : $binary;
    }
}
