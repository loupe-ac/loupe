<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\ProjectListItems;
use App\Module\Project\View\ProjectCursor;
use Symfony\Component\Uid\Uuid;

/**
 * Reads again the rows that a later page of the projects list shows, by id, so
 * that a change never moves a row to another place. It also counts the projects
 * that are newer than the page was at load, because they sit on earlier pages.
 */
final readonly class ListProjectRowsHandler
{
    /** More ids than a page holds are not a page of this list. */
    public const int MAX_IDS = ListProjectsHandler::PER_PAGE;

    public function __construct(
        private ProjectRepository $projects,
        private ProjectListItems $items,
    ) {
    }

    public function __invoke(ListProjectRowsCommand $command): ListProjectRowsView
    {
        $ids = array_values(array_unique(array_filter(
            \array_slice($command->ids, 0, self::MAX_IDS),
            static fn (string $id): bool => Uuid::isValid($id),
        )));
        $ids = array_map(static fn (string $id): string => Uuid::fromString($id)->toRfc4122(), $ids);

        $found = $this->projects->findOwnedBy($command->owner, $ids);
        $foundIds = array_map(static fn ($project): string => (string) $project->id, $found);
        $order = array_flip($ids);
        usort($found, static fn ($a, $b): int => $order[(string) $a->id] <=> $order[(string) $b->id]);

        $cursor = ProjectCursor::parse($command->since);

        return new ListProjectRowsView(
            items: $this->items->forProjects($found),
            goneIds: array_values(array_diff($ids, $foundIds)),
            newerCount: null === $cursor->createdAt || null === $cursor->id
                ? 0
                : $this->projects->countNewerThan($command->owner, $cursor->createdAt, $cursor->id),
        );
    }
}
