<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge;

use App\Module\Workflow\Contract\RepositorySuggestion;
use App\Module\Workflow\Contract\RepositorySuggestions;
use Symfony\Component\Uid\Uuid;

/** Answers the suggestions a test sets, and runs a step once after the next cached read, such as a template update. */
final class FakeRepositorySuggestions implements RepositorySuggestions
{
    public ?\Closure $afterNextRead = null;

    /** @param list<RepositorySuggestion> $suggestions */
    public function __construct(
        public array $suggestions = [],
    ) {
    }

    #[\Override]
    public function forProject(Uuid $projectId): array
    {
        $suggestions = $this->suggestions;
        $step = $this->afterNextRead;
        $this->afterNextRead = null;
        if (null !== $step) {
            $step();
        }

        return $suggestions;
    }

    #[\Override]
    public function forProjectFresh(Uuid $projectId): array
    {
        return $this->suggestions;
    }
}
