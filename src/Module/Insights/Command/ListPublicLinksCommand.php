<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

final readonly class ListPublicLinksCommand
{
    public function __construct(
        public int $page,
        public string $sort,
        public string $dir,
        /** A code, or part of a project name, an owner name or an owner email. */
        public ?string $query = null,
        /** The id of an owner, as the user page links to it. */
        public ?string $userId = null,
    ) {
    }
}
