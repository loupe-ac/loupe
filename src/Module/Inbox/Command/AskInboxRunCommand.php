<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

/** The command entry that a run-command suggestion asks the bridge to run, as the agent wrote it. */
final readonly class AskInboxRunCommand
{
    /**
     * @param array<string, string> $params
     */
    public function __construct(
        public string $entry,
        public string $branch,
        public array $params = [],
    ) {
    }
}
