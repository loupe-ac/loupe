<?php

declare(strict_types=1);

namespace App\Module\Notification\Command;

use App\Module\Notification\Repository\PushDeviceRepository;
use App\Module\Notification\Service\DeviceChangedPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** A device of another user reads as missing, so the answer never confirms that a row exists. */
final readonly class RemovePushDeviceHandler
{
    public function __construct(
        private PushDeviceRepository $pushDevices,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private DeviceChangedPublisher $devicesChanged,
    ) {
    }

    public function __invoke(RemovePushDeviceCommand $command): void
    {
        $device = $this->pushDevices->findOneOwnedBy($command->user, $command->deviceId)
            ?? throw new NotFoundHttpException('No such push device.');

        $deviceId = $command->deviceId->toRfc4122();
        $this->em->remove($device);
        $this->em->flush();

        $this->auditor->record(
            'notification.device_removed',
            AuditOutcome::Success,
            ['deviceId' => $deviceId],
            new AuditSubject('push_device', $deviceId),
        );
        $this->devicesChanged->changed($command->user);
    }
}
