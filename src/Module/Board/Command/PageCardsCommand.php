<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Api\Pagination\PageRequest;
use App\Module\Board\View\CardSort;
use App\Module\Project\Entity\Project;

/**
 * One keyset page of a project's cards. Every filter arrives as the client wrote
 * it, and the handler checks it against the project. $filters repeats them in the
 * form a cursor holds, so the next cursor refuses a walk whose filters change.
 */
final readonly class PageCardsCommand
{
    /**
     * @param array<string, scalar|null> $filters
     */
    public function __construct(
        public Project $project,
        public PageRequest $page,
        public CardSort $sort,
        public array $filters,
        public ?string $query = null,
        public ?string $status = null,
        public ?string $type = null,
        public ?string $reporter = null,
        public ?string $parentCardId = null,
        public ?int $parentNumber = null,
        public ?bool $paused = null,
        public ?string $state = null,
        public ?string $stateCode = null,
    ) {
    }
}
