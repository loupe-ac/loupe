<?php

declare(strict_types=1);

namespace App\Module\Project\Event;

use Symfony\Component\Uid\Uuid;

/**
 * A setting a project edit form shows has changed: the name and details, the
 * allowed origins, the readiness guide or the agent GitHub account. Dispatched
 * after the flush that stored it, with the id only.
 */
final readonly class ProjectSettingsChanged
{
    public function __construct(
        public Uuid $projectId,
    ) {
    }
}
