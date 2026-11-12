<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use Symfony\Component\Uid\Uuid;

final readonly class PageOwnerBridgesCommand
{
    public function __construct(
        public User $owner,
        public int $limit,
        /** A credential bound to one project sees the bridges that serve it. Null sees every bridge of the owner. */
        public ?Uuid $onlyProjectId = null,
        /** The id of the last bridge of the previous page. */
        public ?Uuid $after = null,
    ) {
    }
}
