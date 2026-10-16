<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** A piece of the output log. $length is the byte offset after $text. */
final readonly class WorkerRunOutputSlice
{
    public function __construct(
        public string $text,
        public int $length,
    ) {
    }
}
