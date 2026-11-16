<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\CardTypes;

/** The board-wide values that every card placement of one project shares. */
final readonly class BoardPlacementContext
{
    public function __construct(
        public Project $project,
        public BoardColumn $backlog,
        /** @var list<BoardColumn> the columns other than the Backlog, in order */
        public array $columns,
        public \DateTimeImmutable $windowStart,
        /** @var array<string, int> column id => the number of cards the column shows */
        public array $counts,
        /** @var array<string, int> terminal column id => every card the column holds */
        public array $terminalTotals,
        public CardTypes $types,
        /** @var list<string> */
        public array $laneEpicIds,
        /** @var array<string, int> card id => inbox item number */
        public array $openInboxItems,
    ) {
    }
}
