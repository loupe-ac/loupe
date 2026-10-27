<?php

declare(strict_types=1);

namespace App\Module\Landing\Command;

use App\Module\Landing\ValueObject\ComparisonPage;

final readonly class ShowComparisonView
{
    /** @param list<ComparisonPage> $others every page except the shown one */
    public function __construct(
        public ComparisonPage $page,
        public array $others,
    ) {
    }
}
