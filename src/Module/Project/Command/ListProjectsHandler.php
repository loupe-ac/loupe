<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\ProjectListItems;
use App\Module\Project\View\ProjectCursor;
use App\Utils\PageList;

final readonly class ListProjectsHandler
{
    public const int PER_PAGE = 20;

    public function __construct(
        private ProjectRepository $projects,
        private ProjectListItems $items,
    ) {
    }

    public function __invoke(ListProjectsCommand $command): ListProjectsView
    {
        $paginator = $this->projects->findPaginatedByOwner($command->owner, $command->page, self::PER_PAGE);
        $total = count($paginator);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));

        $projects = iterator_to_array($paginator, false);

        return new ListProjectsView(
            items: $this->items->forProjects($projects),
            liveSince: $command->page > 1 ? $this->liveSince($command->owner) : null,
            totalPages: $totalPages,
            pageList: PageList::build($command->page, $totalPages),
            clampedPage: PageList::clampedPage($command->page, $total, self::PER_PAGE),
        );
    }

    /** Where the list stood at load, for a later page that counts the projects newer than it. */
    private function liveSince(User $owner): string
    {
        $newest = $this->projects->findNewestByOwner($owner, 1)[0] ?? null;

        return ProjectCursor::of($newest)->toString();
    }
}
