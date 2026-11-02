<?php

declare(strict_types=1);

namespace App\Module\OAuth\Service;

use App\Module\Account\Deletion\AccountDataPurgerInterface;
use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use App\Module\OAuth\Repository\ApiKeyRepository;
use Symfony\Component\Uid\Uuid;

/** Every API key of the user. */
final readonly class ApiKeyAccountPurger implements AccountDataPurgerInterface
{
    public function __construct(
        private ApiKeyRepository $apiKeys,
    ) {
    }

    #[\Override]
    public function deletionOrder(): int
    {
        return 46;
    }

    #[\Override]
    public function purge(User $user, AccountDeletionCleanup $cleanup): void
    {
        $id = (string) ($user->id ?? throw new \LogicException('a persisted user always has an id'));
        $this->apiKeys->deleteForOwner(Uuid::fromString($id));
    }
}
