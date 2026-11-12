<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Series;

final readonly class ShowSeriesCommand
{
    public function __construct(
        public Series $series,
    ) {
    }
}
