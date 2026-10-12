<?php

declare(strict_types=1);

namespace App\Module\Artifact\Service;

use App\Module\Account\Deletion\AccountDataPurgerInterface;
use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use Doctrine\DBAL\Connection;

/**
 * Artifacts of a project the user owns go with the project. This purger covers
 * the rest: an artifact or version the user stored in another person's project
 * stays, and only loses its link to the account.
 */
final readonly class ArtifactAccountPurger implements AccountDataPurgerInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function deletionOrder(): int
    {
        return 12;
    }

    #[\Override]
    public function purge(User $user, AccountDeletionCleanup $cleanup): void
    {
        $userId = (string) $user->id;

        $this->connection->executeStatement('UPDATE artifacts SET created_by_id = NULL WHERE created_by_id = :user', ['user' => $userId]);
        $this->connection->executeStatement('UPDATE artifact_versions SET stored_by_id = NULL WHERE stored_by_id = :user', ['user' => $userId]);
    }
}
