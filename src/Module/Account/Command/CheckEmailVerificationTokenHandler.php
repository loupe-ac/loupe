<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;

final readonly class CheckEmailVerificationTokenHandler
{
    public function __construct(
        private UserRepository $users,
    ) {
    }

    /**
     * Returns the user of a valid token, or null. It writes nothing, so a link
     * scanner that opens the page leaves the address unverified.
     */
    public function __invoke(CheckEmailVerificationTokenCommand $command): ?User
    {
        if (null === $command->token || '' === $command->token) {
            return null;
        }

        $user = $this->users->findByEmailVerificationToken($command->token);

        return $user instanceof User && $user->isEmailVerificationTokenValid($command->token) ? $user : null;
    }
}
