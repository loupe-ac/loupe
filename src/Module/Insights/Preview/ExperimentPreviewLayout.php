<?php

declare(strict_types=1);

namespace App\Module\Insights\Preview;

/** What the experiment image draws: the header, at most four rows or cards, and the count of the variants left out. */
final readonly class ExperimentPreviewLayout
{
    public const int LIMIT = 4;

    /** @param list<ExperimentPreviewItem> $items */
    public function __construct(
        /** True for one row per variant against a baseline, false for value cards. */
        public bool $rows,
        public string $title,
        /** The line after the title, or an empty string. */
        public string $subtitle,
        public array $items,
        /** The variants left out by the limit. */
        public int $more,
        /** The position of "no change" from 0 to 100. Null when no row has a change. */
        public ?float $baselineAt = null,
    ) {
    }
}
