<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Api\Pagination\CreatedSort;
use App\Api\Pagination\PageRequest;
use App\Module\Account\Entity\User;
use Symfony\Component\Uid\Uuid;

final readonly class PageProjectsCommand
{
    public function __construct(
        public User $owner,
        /** The one project a credential bound to a project reaches, or null for every project of the owner. */
        public ?Uuid $only,
        public PageRequest $page,
        public CreatedSort $sort,
    ) {
    }
}
