<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Bridge asks this port which workflow template a project follows. */
interface ProjectTemplateKeys
{
    /** @return ?string the key of the template, or null when the project is bound to none */
    public function of(Uuid $projectId): ?string;
}
