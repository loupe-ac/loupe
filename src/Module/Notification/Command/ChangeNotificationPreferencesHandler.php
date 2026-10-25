<?php

declare(strict_types=1);

namespace App\Module\Notification\Command;

use App\Module\Notification\Entity\NotificationPreference;
use App\Module\Notification\Repository\NotificationPreferenceRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Stores only the cells that differ from their default, so a new event or channel needs no backfill. */
final readonly class ChangeNotificationPreferencesHandler
{
    public function __construct(
        private NotificationPreferenceRepository $notificationPreferences,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(ChangeNotificationPreferencesCommand $command): void
    {
        $changed = $this->em->wrapInTransaction(function () use ($command): array {
            // The form submits on every change, so two requests of one user can overlap.
            // The lock on the user row makes the second one read the row of the first.
            $this->em->lock($command->user, LockMode::PESSIMISTIC_WRITE);

            $changed = [];
            foreach ($command->choices as $choice) {
                $row = $this->notificationPreferences->findOneByCell($command->user, $choice->event, $choice->channel);
                $current = $row->enabled ?? $choice->event->defaultEnabled();
                if ($current === $choice->enabled) {
                    continue;
                }

                if ($choice->enabled === $choice->event->defaultEnabled()) {
                    if (null !== $row) {
                        $this->em->remove($row);
                    }
                } elseif (null !== $row) {
                    $row->enabled = $choice->enabled;
                } else {
                    $this->em->persist(new NotificationPreference($command->user, $choice->event, $choice->channel, $choice->enabled));
                }
                $changed[] = \sprintf('%s:%s=%s', $choice->event->value, $choice->channel->value, $choice->enabled ? 'on' : 'off');
            }

            return $changed;
        });

        if ([] === $changed) {
            return;
        }

        $userId = (string) $command->user->id;
        $this->auditor->record(
            'notification.preferences_changed',
            AuditOutcome::Success,
            ['userId' => $userId, 'changes' => implode(',', $changed)],
            new AuditSubject('user', $userId),
        );
    }
}
