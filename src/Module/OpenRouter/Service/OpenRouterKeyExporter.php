<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\OpenRouter\Repository\OpenRouterKeyRepository;

/** Exports that a key exists and its dates, never the key and never its last characters. */
final readonly class OpenRouterKeyExporter implements UserDataExporterInterface
{
    public function __construct(
        private OpenRouterKeyRepository $openRouterKeys,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'openrouter_keys.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->openRouterKeys->findExportRowsForUser($user->id ?? throw new \LogicException('a persisted user always has an id')) as $row) {
            yield [
                'scope' => null === $row['projectId'] ? 'account' : 'project',
                'projectId' => $row['projectId'],
                'state' => $row['state']->value,
                'createdAt' => $row['createdAt']->format(\DateTimeInterface::ATOM),
                'refusedSince' => $row['refusedSince']?->format(\DateTimeInterface::ATOM),
                'lastReadAt' => $row['lastReadAt']?->format(\DateTimeInterface::ATOM),
            ];
        }
    }
}
