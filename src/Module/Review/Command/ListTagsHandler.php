<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Repository\TagRepository;

final readonly class ListTagsHandler
{
    public function __construct(
        private TagRepository $tags,
    ) {
    }

    public function __invoke(ListTagsCommand $command): ListTagsView
    {
        if (null === $command->limit) {
            return new ListTagsView($this->tags->findByProjectWithDocumentCounts($command->project));
        }

        $rows = $this->tags->pageByProjectWithDocumentCounts($command->project, $command->afterName, $command->limit + 1);

        return new ListTagsView(\array_slice($rows, 0, $command->limit), \count($rows) > $command->limit);
    }
}
