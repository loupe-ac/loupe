<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Repository\SeriesRepository;

final readonly class ListSeriesHandler
{
    public function __construct(
        private SeriesRepository $series,
    ) {
    }

    public function __invoke(ListSeriesCommand $command): ListSeriesView
    {
        if (null === $command->limit) {
            return new ListSeriesView($this->series->findByProjectWithDocumentCounts($command->project));
        }

        $rows = $this->series->pageByProjectWithDocumentCounts($command->project, $command->afterNormalizedName, $command->limit + 1);

        return new ListSeriesView(\array_slice($rows, 0, $command->limit), \count($rows) > $command->limit);
    }
}
