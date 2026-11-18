<?php

declare(strict_types=1);

namespace App\Module\OAuth\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\OAuth\Repository\WorkerRunCredentialRepository;

/** Each worker run credential issued for the user, with its run and dates. The token never leaves the database. */
final readonly class WorkerRunCredentialsExporter implements UserDataExporterInterface
{
    public function __construct(
        private WorkerRunCredentialRepository $workerRunCredentials,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'worker_run_credentials.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->workerRunCredentials->findByUser($user) as $credential) {
            yield [
                'projectId' => (string) $credential->project->id,
                'bridgeId' => $credential->bridgeId->toRfc4122(),
                'runKey' => $credential->runKey->toRfc4122(),
                'sessionId' => $credential->sessionId->toRfc4122(),
                'issuedAt' => $credential->issuedAt->format(\DATE_ATOM),
                'expiresAt' => $credential->expiresAt->format(\DATE_ATOM),
                'revokedAt' => $credential->revokedAt?->format(\DATE_ATOM),
            ];
        }
    }
}
