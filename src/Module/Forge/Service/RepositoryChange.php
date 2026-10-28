<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use Symfony\Component\Uid\Uuid;

/** What a write inside RepositoryHoldSummaries::change() reports back to the writer. */
final class RepositoryChange
{
    /** @var array<string, Uuid> */
    private array $signals = [];

    /** Asks for a live signal after the commit, whether or not the summary changed. */
    public function signal(Uuid $repositoryId): void
    {
        $this->signals[(string) $repositoryId] = $repositoryId;
    }

    /** @return list<Uuid> */
    public function signals(): array
    {
        return array_values($this->signals);
    }
}
