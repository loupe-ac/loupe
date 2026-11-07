<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Lists the repositories that a project holds. Forge implements it. */
interface ProjectRepositories
{
    /** @return list<RepositoryRef> the repositories of the project, oldest first */
    public function of(Uuid $projectId): array;
}
