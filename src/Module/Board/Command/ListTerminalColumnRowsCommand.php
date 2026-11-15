<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;

final readonly class ListTerminalColumnRowsCommand
{
    public function __construct(
        /** A terminal column. */
        public BoardColumn $column,
        /** @var list<string> the card ids of the rows the page shows */
        public array $ids,
        /** The moment the page rendered. */
        public \DateTimeImmutable $since,
    ) {
    }
}
