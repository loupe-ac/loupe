<?php

declare(strict_types=1);

namespace App\Module\Account\Event;

use App\Module\Account\Entity\User;

/**
 * Dispatched after the flush that first sets an existing account's
 * `emailVerifiedAt`, by every path that can do it. It does not fire for an
 * account that was already verified, nor for a new social sign-up, which is
 * verified at creation and announced by UserRegistered.
 */
final readonly class UserEmailVerified
{
    public function __construct(
        public User $user,
    ) {
    }
}
