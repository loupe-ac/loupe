<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Account\Entity\User;

final readonly class ListProjectRowsCommand
{
    /** @param list<string> $ids the ids of the rows the page shows, unchecked */
    public function __construct(
        public User $owner,
        public array $ids,
        /** The cursor the page rendered at load. */
        public string $since,
    ) {
    }
}
