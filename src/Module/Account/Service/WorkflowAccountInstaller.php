<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Entity\User;
use Doctrine\DBAL\Connection;

/**
 * Restores the singleton workflow account after something has wiped `users`.
 * It writes the same row as the migration that first installs the account, and
 * it uses SQL for the same reason AgentAccountInstaller does.
 */
final class WorkflowAccountInstaller
{
    public static function install(Connection $connection): void
    {
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO users (id, roles, full_name, email, password, created_at)
                VALUES (:id, '[]', 'Workflow', 'workflow@loupe.invalid', NULL, now())
                ON CONFLICT (id) DO NOTHING
                SQL,
            ['id' => User::WORKFLOW_ID],
        );
    }
}
