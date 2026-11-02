<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Api\Pagination\PageRequest;
use App\Module\Board\Entity\Card;

final readonly class PageCardEventsCommand
{
    public function __construct(
        public Card $card,
        public PageRequest $page,
    ) {
    }
}
