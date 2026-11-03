<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Api\Pagination\Page;
use App\Module\Board\Entity\CardEvent;

/** One page of a card's history, newest first. */
final readonly class PageCardEventsView
{
    /** @param Page<CardEvent> $page */
    public function __construct(
        public Page $page,
    ) {
    }
}
