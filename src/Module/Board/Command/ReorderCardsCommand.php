<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;

/**
 * Places a block of cards of one column. The cards of $cardIds stay together in
 * their listed order, and the other cards of the column keep their order around
 * them. $neighbourId names the card the block lands before or after, and is
 * null for the top and the bottom.
 */
final readonly class ReorderCardsCommand
{
    /** @param list<string> $cardIds RFC 4122 ids, the block from top to bottom */
    public function __construct(
        public Project $project,
        public BoardColumn $column,
        public array $cardIds,
        public ReorderPlacement $placement,
        public ?string $neighbourId,
        public Actor $actor,
    ) {
    }
}
