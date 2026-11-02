<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Api\Pagination\Cursor;
use App\Api\Pagination\Page;
use App\Api\Problem\ApiProblemException;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;

final readonly class PageProjectsHandler
{
    public function __construct(
        private ProjectRepository $projects,
    ) {
    }

    /**
     * @return Page<Project>
     *
     * @throws ApiProblemException `invalid_cursor` for a cursor whose position does not parse
     */
    public function __invoke(PageProjectsCommand $command): Page
    {
        $newestFirst = $command->sort->newestFirst();
        $rows = $this->projects->findPageByOwner(
            $command->owner,
            $command->only,
            $command->page->fetchSize(),
            $newestFirst,
            $command->page->cursor?->createdPosition(),
        );

        return Page::fromRows($rows, $command->page, fn (Project $project): Cursor => Cursor::afterCreated(
            $command->sort->value,
            $project->createdAt,
            $project->id ?? throw new \LogicException('A stored project has an id.'),
        ));
    }
}
