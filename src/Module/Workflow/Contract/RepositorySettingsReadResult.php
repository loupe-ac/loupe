<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** What a read of `.loupe.yaml` found wrong, as warning lines for the repository check. */
final readonly class RepositorySettingsReadResult
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public array $warnings = [],
    ) {
    }
}
