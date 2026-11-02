<?php

declare(strict_types=1);

namespace App\Module\OAuth\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\OAuth\Repository\ApiKeyRepository;

/** Each API key of the user with its dates. The key value and its hash never leave the database. */
final readonly class ApiKeysExporter implements UserDataExporterInterface
{
    public function __construct(
        private ApiKeyRepository $apiKeys,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'api_keys.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->apiKeys->findByOwner($user) as $key) {
            yield [
                'name' => $key->name,
                'displayStart' => $key->displayStart,
                'createdAt' => $key->createdAt->format(\DATE_ATOM),
                'expiresAt' => $key->expiresAt?->format(\DATE_ATOM),
                'lastUsedAt' => $key->lastUsedAt?->format(\DATE_ATOM),
                'revokedAt' => $key->revokedAt?->format(\DATE_ATOM),
            ];
        }
    }
}
