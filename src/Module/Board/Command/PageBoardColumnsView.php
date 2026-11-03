<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Api\Pagination\Page;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Workflow\Contract\CardTypes;

/** One page of a board's columns, with the card types of the project. */
final readonly class PageBoardColumnsView
{
    /** @param Page<BoardColumn> $page */
    public function __construct(
        public Page $page,
        public CardTypes $types,
    ) {
    }
}
