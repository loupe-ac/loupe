<?php

declare(strict_types=1);

namespace App\Module\Notification\Service;

use App\Module\Account\Deletion\AccountDataPurgerInterface;
use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use App\Module\Notification\Repository\PushDeviceRepository;
use Symfony\Component\Uid\Uuid;

final readonly class PushDeviceAccountPurger implements AccountDataPurgerInterface
{
    public function __construct(
        private PushDeviceRepository $pushDevices,
    ) {
    }

    /** Runs after ProjectAccountPurger, which clears the EntityManager, so the user may be detached. */
    #[\Override]
    public function deletionOrder(): int
    {
        return 46;
    }

    #[\Override]
    public function purge(User $user, AccountDeletionCleanup $cleanup): void
    {
        $id = (string) ($user->id ?? throw new \LogicException('a persisted user always has an id'));
        $this->pushDevices->deleteForOwner(Uuid::fromString($id));
    }
}
