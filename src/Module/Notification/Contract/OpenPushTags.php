<?php

declare(strict_types=1);

namespace App\Module\Notification\Contract;

use App\Module\Account\Entity\User;

/**
 * The tags of the pushes that still stand for a user. A module that sends pushes implements it,
 * so Notification never imports that module.
 */
interface OpenPushTags
{
    /** @return list<string> */
    public function forUser(User $user): array;
}
