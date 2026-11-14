<?php

declare(strict_types=1);

namespace App\Module\Project\Contract;

use Symfony\Component\Uid\Uuid;

/** The facts that make a project set up. A project is set up once it reached both milestones. */
interface ProjectMilestones
{
    public const string INIT_FINISHED = 'init-finished';
    public const string BRIDGE_CONNECTED = 'bridge-connected';

    /**
     * Records the milestone, and turns the set-up flag on when both milestones exist. A milestone
     * that exists already changes nothing, and the check still runs. A project that is gone is skipped.
     */
    public function reach(Uuid $projectId, string $key): void;
}
