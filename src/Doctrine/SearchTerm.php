<?php

declare(strict_types=1);

namespace App\Doctrine;

final readonly class SearchTerm
{
    public function __construct(
        public string $text,
        public bool $excluded,
    ) {
    }
}
