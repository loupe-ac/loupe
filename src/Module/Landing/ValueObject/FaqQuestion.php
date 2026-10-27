<?php

declare(strict_types=1);

namespace App\Module\Landing\ValueObject;

final readonly class FaqQuestion
{
    public function __construct(
        public string $text,
        public string $anchor,
    ) {
    }
}
