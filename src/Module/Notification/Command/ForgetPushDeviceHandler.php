<?php

declare(strict_types=1);

namespace App\Module\Notification\Command;

use App\Module\Notification\Repository\PushDeviceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Drops the device of this browser when its user signs out, so the next user of the browser gets no push meant for the last one. */
final readonly class ForgetPushDeviceHandler
{
    public function __construct(
        private PushDeviceRepository $pushDevices,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(ForgetPushDeviceCommand $command): void
    {
        $device = $this->pushDevices->findOneByOwnerAndTokenHash($command->user, $command->deviceTokenHash);
        if (null === $device) {
            return;
        }

        $deviceId = (string) $device->id;
        $this->em->remove($device);
        $this->em->flush();

        $this->auditor->record(
            'notification.device_forgotten',
            AuditOutcome::Success,
            ['deviceId' => $deviceId],
            new AuditSubject('push_device', $deviceId),
        );
    }
}
