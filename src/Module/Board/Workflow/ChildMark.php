<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

/** The open child whose stored marker of one kind began first. */
final readonly class ChildMark
{
    public function __construct(
        public int $number,
        public string $title,
        public \DateTimeImmutable $since,
    ) {
    }
}
