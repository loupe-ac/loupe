<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Account\Deletion\AccountDataPurgerInterface;
use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use Doctrine\DBAL\Connection;

final readonly class InboxActionAccountPurger implements AccountDataPurgerInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /** Runs after ProjectAccountPurger, whose project deletes cascade the actions of an owned project. */
    #[\Override]
    public function deletionOrder(): int
    {
        return 21;
    }

    #[\Override]
    public function purge(User $user, AccountDeletionCleanup $cleanup): void
    {
        $this->connection->executeStatement('DELETE FROM inbox_actions WHERE requested_by_id = :user', ['user' => (string) $user->id]);
    }
}
