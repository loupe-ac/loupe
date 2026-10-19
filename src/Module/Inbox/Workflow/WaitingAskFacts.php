<?php

declare(strict_types=1);

namespace App\Module\Inbox\Workflow;

/** The open asks whose session stopped on a run of the card and still waits for a blocking answer. */
final readonly class WaitingAskFacts
{
    /** @param list<string> $askIds RFC 4122 strings, in id order */
    public function __construct(
        public array $askIds,
    ) {
    }

    public function waits(): bool
    {
        return [] !== $this->askIds;
    }
}
