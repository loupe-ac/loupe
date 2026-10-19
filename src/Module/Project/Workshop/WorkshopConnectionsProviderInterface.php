<?php

declare(strict_types=1);

namespace App\Module\Project\Workshop;

use App\Module\Project\Entity\Project;

interface WorkshopConnectionsProviderInterface
{
    /** @return list<WorkshopConnection> */
    public function forProject(Project $project): array;

    /**
     * Every bridge of the project owner, whichever projects it follows, newest heartbeat first.
     * The repository fields stay null, because they name one project.
     *
     * @return list<WorkshopConnection>
     */
    public function forOwnerOf(Project $project): array;
}
