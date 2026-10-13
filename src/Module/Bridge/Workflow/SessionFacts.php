<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

/** The oldest open interactive run of the card: its start, null with none, and its name, if it has one. */
final readonly class SessionFacts
{
    public function __construct(
        public ?\DateTimeImmutable $openSince,
        public ?string $kind,
    ) {
    }
}
