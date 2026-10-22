<?php

declare(strict_types=1);

namespace App\Module\Inbox\Workflow;

/** The documents in review of the card that an open review item of an agent ask targets. */
final readonly class AgentReviewOpenFacts
{
    /** @param list<string> $documentIds RFC 4122 strings, in id order */
    public function __construct(
        public array $documentIds,
    ) {
    }

    public function open(): bool
    {
        return [] !== $this->documentIds;
    }
}
