<?php

declare(strict_types=1);

namespace App\Module\Landing\Command;

final readonly class StampDocsResult
{
    public function __construct(
        public int $pages,
        /** @var list<string> */
        public array $problems,
    ) {
    }
}
