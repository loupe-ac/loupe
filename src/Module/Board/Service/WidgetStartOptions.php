<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardMoveGuard;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\CardTypeCatalog;
use Symfony\Component\Uid\Uuid;

/** Lists the columns the move guard allows a new widget card to start in, with the actor the widget records. */
final readonly class WidgetStartOptions
{
    public function __construct(
        private BoardColumnRepository $boardColumns,
        private CardMoveGuard $guard,
        private CardTypeCatalog $catalog,
    ) {
    }

    /** @return list<BoardColumn> every non-terminal column that a card in Backlog may move to, in board order */
    public function allowedFromBacklog(Project $project): array
    {
        return $this->allowedAmong($project, $this->boardColumns->findForProject($project));
    }

    /**
     * @param list<BoardColumn> $columns
     *
     * @return list<BoardColumn>
     */
    public function allowedAmong(Project $project, array $columns): array
    {
        $backlog = array_find($columns, static fn (BoardColumn $column): bool => $column->backlog);
        if (null === $backlog) {
            return [];
        }
        $snapshot = new CardSnapshot(
            Uuid::v7(),
            $project->requireId(),
            0,
            $this->catalog->forProject($project->requireId())->defaultKey,
            $backlog->ref(),
            null,
            null,
            null,
        );

        return array_values(array_filter(
            $columns,
            fn (BoardColumn $column): bool => !$column->backlog
                && !$column->terminal
                && $this->guard->allows($snapshot, $column->ref(), Actor::Reviewer, null),
        ));
    }
}
