<?php

declare(strict_types=1);

namespace App\Api\Idempotency\Service;

use App\Api\Idempotency\Repository\IdempotencyRecordRepository;
use App\Module\Account\Deletion\AccountDataPurgerInterface;
use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use Symfony\Component\Uid\Uuid;

/** The stored answers of the user can hold the user's data, so they leave with the account. */
final readonly class IdempotencyAccountPurger implements AccountDataPurgerInterface
{
    public function __construct(
        private IdempotencyRecordRepository $idempotencyRecords,
    ) {
    }

    #[\Override]
    public function deletionOrder(): int
    {
        return 11;
    }

    #[\Override]
    public function purge(User $user, AccountDeletionCleanup $cleanup): void
    {
        $id = (string) ($user->id ?? throw new \LogicException('a persisted user always has an id'));
        $this->idempotencyRecords->deleteForOwner(Uuid::fromString($id));
    }
}
