<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

/**
 * The repositories of a project that block new work, with the count of blocking
 * gaps of each. Two summaries are equal when they name the same repositories
 * with the same counts.
 */
final readonly class RepositoryHoldSummary
{
    /** @var array<string, int> the count of blocking gaps, by repository id */
    public array $blocking;

    /** @param array<string, int> $blocking */
    public function __construct(array $blocking = [])
    {
        ksort($blocking);
        $this->blocking = $blocking;
    }

    public function isEmpty(): bool
    {
        return [] === $this->blocking;
    }

    public function equals(self $other): bool
    {
        return $this->blocking === $other->blocking;
    }
}
