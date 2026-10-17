<?php

declare(strict_types=1);

namespace App\Module\Inbox\Workflow;

/** An open inbox item that asks a person something about the card. */
final readonly class OpenQuestion
{
    public function __construct(
        public int $number,
        public string $title,
        public \DateTimeImmutable $since,
        public bool $blocking,
    ) {
    }
}
