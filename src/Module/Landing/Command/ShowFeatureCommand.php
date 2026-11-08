<?php

declare(strict_types=1);

namespace App\Module\Landing\Command;

final readonly class ShowFeatureCommand
{
    public function __construct(
        public string $slug,
    ) {
    }
}
