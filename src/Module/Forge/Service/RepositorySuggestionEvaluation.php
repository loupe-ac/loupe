<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Workflow\Contract\RepositorySuggestionKey;

/** The verdict of one suggestion on one read of a repository. */
final readonly class RepositorySuggestionEvaluation
{
    /** @param list<string> $gaps the reason codes of the gaps, empty when the repository fits */
    public function __construct(
        public RepositorySuggestionKey $key,
        public array $gaps,
    ) {
    }

    public function fits(): bool
    {
        return [] === $this->gaps;
    }
}
