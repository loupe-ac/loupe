<?php

declare(strict_types=1);

namespace App\Module\Notification\Command;

use App\Module\Notification\Repository\PushDeviceRepository;
use App\Module\Notification\Service\DeviceChangedPublisher;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Closes the warning of a stopped device. The device stays in the list, and a
 * new subscription from the same browser opens a new warning if it stops again.
 * A device of another user reads as missing, so the answer never confirms that a row exists.
 */
final readonly class DismissPushDeviceWarningHandler
{
    public function __construct(
        private PushDeviceRepository $pushDevices,
        private ClockInterface $clock,
        private Auditor $auditor,
        private DeviceChangedPublisher $devicesChanged,
    ) {
    }

    public function __invoke(DismissPushDeviceWarningCommand $command): void
    {
        if (null === $this->pushDevices->findOneOwnedBy($command->user, $command->deviceId)) {
            throw new NotFoundHttpException('No such push device.');
        }

        $deviceId = $command->deviceId->toRfc4122();
        if (!$this->pushDevices->dismissWarning($command->deviceId, $this->clock->now())) {
            return;
        }

        $this->auditor->record(
            'notification.device_warning_dismissed',
            AuditOutcome::Success,
            ['deviceId' => $deviceId],
            new AuditSubject('push_device', $deviceId),
        );
        $this->devicesChanged->changed($command->user);
    }
}
