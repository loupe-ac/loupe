<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\View\BacklogListQuery;

final readonly class ListBacklogRowsCommand
{
    public function __construct(
        public BoardColumn $backlog,
        public BacklogListQuery $listQuery,
        /** @var list<string> the card ids of the rows the page shows */
        public array $ids,
        /** The moment the page rendered. Page one reads its own page, so it needs none. */
        public ?\DateTimeImmutable $since,
    ) {
    }
}
