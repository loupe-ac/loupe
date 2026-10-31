<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Service\BridgeLiveness;
use App\Module\Inbox\Entity\InboxItem;
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
     * @return array<string, list<Bridge>> keyed by the project id: the live bridges that follow the project and open sessions
     */
    public function liveFor(array $projects): array
    {
        $live = [];
        foreach ($projects as $project) {
            $key = (string) $project->id;
            if (isset($live[$key])) {
                continue;
            }
            $bridges = array_values(array_filter($this->bridges->findFollowingProject($project), static fn (Bridge $bridge): bool => $bridge->opensSessions()));
            $status = $this->liveness->forOwner($project->owner, array_map(static fn (Bridge $bridge): Uuid => $bridge->id, $bridges));
            $live[$key] = array_values(array_filter($bridges, static fn (Bridge $bridge): bool => isset($status[$bridge->id->toRfc4122()]) && !$status[$bridge->id->toRfc4122()]->quiet));
        }

        return $live;
    }

    /**
     * @param list<Project> $projects
     *
     * @return array<string, bool> keyed by the project id
     */
    public function onlineFor(array $projects): array
    {
        return array_map(static fn (array $bridges): bool => [] !== $bridges, $this->liveFor($projects));
    }

    /** Whether the bridge runs a session about an inbox item, which no card carries. */
    public static function opensItemSessions(Bridge $bridge): bool
    {
        return \in_array(Bridge::workKindOf(InboxItem::SUBJECT_TYPE, WorkRequest::SESSION_KIND), $bridge->workKinds, true);
    }
}
