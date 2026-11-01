<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Repository\BoardColumnRepository;

/**
 * Reorders the board from the slugs of its columns, for a caller that holds no
 * column ids. It maps the slugs to ids, then hands the order to
 * ReorderBoardColumnsHandler, which checks it again under the project lock.
 */
final readonly class ReorderBoardColumnsBySlugHandler
{
    public const string BACKLOG_FIRST = 'board.column.error.reorder_backlog_first';
    public const string UNKNOWN_SLUG = 'board.column.error.reorder_unknown_slug';
    public const string EACH_ONCE = 'board.column.error.reorder_each_once';

    public function __construct(
        private BoardColumnRepository $boardColumns,
        private ReorderBoardColumnsHandler $reorderColumns,
    ) {
    }

    public function __invoke(ReorderBoardColumnsBySlugCommand $command): void
    {
        $ids = [];
        foreach ($this->boardColumns->findForProject($command->project) as $column) {
            if (!$column->backlog) {
                $ids[$column->slug] = (string) $column->id;
            }
        }
        $slugs = implode(', ', array_keys($ids));

        $wanted = [];
        foreach ($command->slugs as $slug) {
            if (BoardColumn::BACKLOG_SLUG === $slug) {
                throw new DomainErrors(['order' => self::BACKLOG_FIRST]);
            }
            if (!\is_string($slug) || !isset($ids[$slug])) {
                throw new DomainErrors(['order' => self::UNKNOWN_SLUG], ['order' => ['%slug%' => \is_string($slug) ? $slug : get_debug_type($slug), '%slugs%' => $slugs]]);
            }
            $wanted[] = $ids[$slug];
        }
        if (\count($wanted) !== \count($ids) || \count(array_unique($wanted)) !== \count($wanted)) {
            throw new DomainErrors(['order' => self::EACH_ONCE], ['order' => ['%slugs%' => $slugs]]);
        }

        ($this->reorderColumns)(new ReorderBoardColumnsCommand($command->project, implode(',', $wanted), implode(',', $ids)));
    }
}
