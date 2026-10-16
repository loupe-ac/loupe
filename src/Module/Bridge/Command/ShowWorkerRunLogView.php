<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

/** The whole stored output log of a run. $cut is true when the log hit the size cap. */
final readonly class ShowWorkerRunLogView
{
    public function __construct(
        public string $text,
        public bool $cut,
    ) {
    }
}
