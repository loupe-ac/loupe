<?php

declare(strict_types=1);

namespace App\Module\Landing\Command;

final readonly class ShowComparisonCommand
{
    public function __construct(
        public string $slug,
    ) {
    }
}
