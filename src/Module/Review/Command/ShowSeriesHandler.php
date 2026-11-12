<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Repository\SeriesRepository;

final readonly class ShowSeriesHandler
{
    public function __construct(
        private SeriesRepository $series,
    ) {
    }

    /** @return array{series: \App\Module\Review\Entity\Series, documentCount: int, highestOrdinal: ?int} */
    public function __invoke(ShowSeriesCommand $command): array
    {
        return $this->series->oneWithDocumentCounts($command->series);
    }
}
