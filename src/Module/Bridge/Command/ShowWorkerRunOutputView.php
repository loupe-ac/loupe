<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

/** A piece of the output log. $length is the byte offset after $text. */
final readonly class ShowWorkerRunOutputView
{
    public function __construct(
        public string $text,
        public int $length,
        public bool $cut,
    ) {
    }
}
