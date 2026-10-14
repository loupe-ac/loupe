<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Service\BridgeLiveness;
use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

/** Says whether a live bridge of the project owner can open an interactive session. */
final readonly class SessionBridges
{
    public function __construct(
        private BridgeRepository $bridges,
        private BridgeLiveness $liveness,
    ) {
    }

    /**
     * One bridge read and one liveness read for each project.
     *
     * @param list<Project> $projects
     *
     * @return array<string, bool> keyed by the project id
     */
    public function onlineFor(array $projects): array
    {
        $online = [];
        foreach ($projects as $project) {
            $key = (string) $project->id;
            if (isset($online[$key])) {
                continue;
            }
            $bridges = array_values(array_filter($this->bridges->findFollowingProject($project), static fn (Bridge $bridge): bool => $bridge->opensSessions()));
            $online[$key] = [] !== $bridges && array_any(
                $this->liveness->forOwner($project->owner, array_map(static fn (Bridge $bridge): Uuid => $bridge->id, $bridges)),
                static fn ($status): bool => !$status->quiet,
            );
        }

        return $online;
    }
}
