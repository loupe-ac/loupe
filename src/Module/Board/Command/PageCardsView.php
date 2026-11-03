<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Api\Pagination\Page;
use App\Module\Board\Entity\Card;

/** One page of a project's cards. */
final readonly class PageCardsView
{
    /** @param Page<Card> $page */
    public function __construct(
        public Page $page,
    ) {
    }
}
