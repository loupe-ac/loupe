<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

/** What an append did. $length is the bytes now stored, and $capped says the log takes no more. */
final readonly class AppendWorkerRunOutputResult
{
    public function __construct(
        public bool $projectFound,
        public bool $runFound,
        public int $length,
        public bool $capped,
    ) {
    }
}
