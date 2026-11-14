<?php

declare(strict_types=1);

namespace App\Module\Project\Contract\Event;

use Symfony\Component\Uid\Uuid;

/** Dispatched after the commit that stored a milestone of a project for the first time. */
final readonly class ProjectMilestoneReached
{
    public function __construct(
        public Uuid $projectId,
        public string $key,
    ) {
    }
}
