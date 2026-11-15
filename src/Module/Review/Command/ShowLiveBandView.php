<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

/** What the live band of a review page shows: the archive state and a newer version. */
final readonly class ShowLiveBandView
{
    /** @param int $newerVersionNumber the latest version when the page shows an older one, and 0 otherwise */
    public function __construct(
        public bool $archived,
        public int $newerVersionNumber,
    ) {
    }
}
