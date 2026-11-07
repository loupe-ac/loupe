<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

final readonly class ListPublicLinksView
{
    /**
     * @param list<PublicLinkProjectRow> $rows
     * @param list<int|null>             $pageList
     * @param array<string, string>      $filters  the active filters, empty values stripped
     */
    public function __construct(
        public array $rows,
        public int $total,
        public int $totalPages,
        public array $pageList,
        public array $filters,
        public ?int $clampedPage,
        /** Whether the instance flag lets any public link show. */
        public bool $flagOn,
    ) {
    }
}
