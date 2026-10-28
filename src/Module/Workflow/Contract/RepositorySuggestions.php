<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Forge asks this port which repository settings the workflow template of a project suggests, and the stored template copy holds the answer. */
interface RepositorySuggestions
{
    /**
     * @return list<RepositorySuggestion> in template order, empty for a project bound to no template
     */
    public function forProject(Uuid $projectId): array;

    /**
     * Reads the template copy again, so a caller that holds the project lock sees a template update another transaction committed.
     *
     * @return list<RepositorySuggestion> in template order, empty for a project bound to no template
     */
    public function forProjectFresh(Uuid $projectId): array;
}
