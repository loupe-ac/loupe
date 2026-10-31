<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Service;

use App\Module\Account\Deletion\AccountDataPurgerInterface;
use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use App\Module\OpenRouter\Repository\OpenRouterKeyRepository;

/**
 * The account key of the user. Project keys go with their projects, which the
 * project purger deletes first. OpenRouter keeps the key until the person
 * revokes it there.
 */
final readonly class OpenRouterKeyAccountPurger implements AccountDataPurgerInterface
{
    public function __construct(
        private OpenRouterKeyRepository $openRouterKeys,
    ) {
    }

    #[\Override]
    public function deletionOrder(): int
    {
        return 48;
    }

    #[\Override]
    public function purge(User $user, AccountDeletionCleanup $cleanup): void
    {
        $this->openRouterKeys->deleteForUser($user->id ?? throw new \LogicException('a persisted user always has an id'));
    }
}
