<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

/** The findings of the failed agent review that one round answered. */
final readonly class WorkLimitRoundFinding
{
    /** @param list<string> $titles */
    public function __construct(
        public int $round,
        public array $titles,
    ) {
    }
}
