<?php

declare(strict_types=1);

namespace App\Module\Notification\Service;

use App\Module\Account\Deletion\AccountDataPurgerInterface;
use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use App\Module\Notification\Repository\NotificationPreferenceRepository;
use Symfony\Component\Uid\Uuid;

final readonly class NotificationPreferenceAccountPurger implements AccountDataPurgerInterface
{
    public function __construct(
        private NotificationPreferenceRepository $notificationPreferences,
    ) {
    }

    /** Runs after ProjectAccountPurger, which clears the EntityManager, so the user may be detached. */
    #[\Override]
    public function deletionOrder(): int
    {
        return 47;
    }

    #[\Override]
    public function purge(User $user, AccountDeletionCleanup $cleanup): void
    {
        $id = (string) ($user->id ?? throw new \LogicException('a persisted user always has an id'));
        $this->notificationPreferences->deleteForOwner(Uuid::fromString($id));
    }
}
