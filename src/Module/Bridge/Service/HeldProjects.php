<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Workflow\Contract\WorkHolds;
use Symfony\Component\Uid\Uuid;

/**
 * Tells which projects hold their new work. Each answer takes a key share lock on the project row
 * that lasts until the transaction ends, so call it in the transaction that acts on the answer.
 */
final readonly class HeldProjects
{
    public function __construct(
        private WorkHolds $workHolds,
    ) {
    }

    /**
     * @param list<string> $projectIds RFC 4122 ids
     *
     * @return list<string> the ids among them that hold their new work
     */
    public function among(array $projectIds): array
    {
        return array_values(array_filter(
            $projectIds,
            fn (string $projectId): bool => null !== $this->workHolds->heldProject(Uuid::fromString($projectId)),
        ));
    }
}
