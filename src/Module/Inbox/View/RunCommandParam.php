<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

/** One parameter of a command entry with the value the run would use. */
final readonly class RunCommandParam
{
    public function __construct(
        public string $name,
        public string $value,
        /** The pattern the whole value must match. Null when the entry is missing and Loupe cannot tell. */
        public ?string $pattern,
        public ?string $description,
        /** False when the value fails the pattern, or when the entry has no such parameter. */
        public bool $valid,
    ) {
    }
}
