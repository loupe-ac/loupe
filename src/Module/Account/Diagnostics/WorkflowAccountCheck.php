<?php

declare(strict_types=1);

namespace App\Module\Account\Diagnostics;

use App\Module\Account\Entity\User;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Ubermuda\HealthCheckBundle\Diagnostic;
use Ubermuda\HealthCheckBundle\DiagnosticInterface;
use Ubermuda\HealthCheckBundle\DiagnosticState;

/**
 * Every verdict a workflow rule writes is authored by one singleton user row,
 * so without it the first automatic approval fails inside the engine pass,
 * far from the cause.
 */
final readonly class WorkflowAccountCheck implements DiagnosticInterface
{
    private const string WORKFLOW_ACCOUNT_SQL = 'SELECT COUNT(*) FROM users WHERE id = :id'; // @translation-check-ignore

    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public static function priority(): int
    {
        return 21;
    }

    #[\Override]
    public function __invoke(): Diagnostic
    {
        try {
            $present = (int) $this->connection->fetchOne(self::WORKFLOW_ACCOUNT_SQL, ['id' => User::WORKFLOW_ID]);
        } catch (\Throwable $e) {
            $this->logger->warning('account.system_status_workflow_account_unreadable', ['exception' => $e]);

            return new Diagnostic('workflow_account', DiagnosticState::Unknown, 'account.system_status.workflow_account.unreadable');
        }

        if (0 === $present) {
            return new Diagnostic('workflow_account', DiagnosticState::Failed, 'account.system_status.workflow_account.missing');
        }

        return new Diagnostic('workflow_account', DiagnosticState::Ok, 'account.system_status.workflow_account.present');
    }
}
