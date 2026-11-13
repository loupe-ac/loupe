<?php

declare(strict_types=1);

namespace App\Module\Project\Contract\Event;

use Symfony\Component\Uid\Uuid;

/** Dispatched after the commit that turned the set-up flag of a project on. It fires once per project. */
final readonly class ProjectSetUp
{
    public function __construct(
        public Uuid $projectId,
    ) {
    }
}
