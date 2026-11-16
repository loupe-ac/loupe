<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Project\Entity\Project;
use App\Module\Project\Workshop\WorkshopConnection;
use App\Module\Project\Workshop\WorkshopConnectionsProviderInterface;
use App\Module\Project\Workshop\WorkshopGitIdentity;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsAlias(WorkshopConnectionsProviderInterface::class)]
final readonly class BridgeWorkshopConnectionsProvider implements WorkshopConnectionsProviderInterface
{
    public function __construct(
        private BridgeRepository $bridges,
        private BridgeLiveness $liveness,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[\Override]
    public function forProject(Project $project): array
    {
        $bridges = $this->bridges->findFollowingProject($project);
        usort($bridges, static fn (Bridge $a, Bridge $b): int => $b->lastSeenAt <=> $a->lastSeenAt);
        $statuses = $this->liveness->forOwner($project->owner, array_map(static fn (Bridge $bridge) => $bridge->id, $bridges));
        $url = $this->urls->generate('app_project_agents', ['id' => (string) $project->id]);
        $projectId = (string) $project->id;

        return array_map(static function (Bridge $bridge) use ($statuses, $url, $projectId): WorkshopConnection {
            $id = (string) $bridge->id;
            $status = $statuses[$bridge->id->toRfc4122()];
            $repository = array_find($bridge->repositories ?? [], static fn (array $row): bool => $row['projectId'] === $projectId);
            $identity = array_find($bridge->gitIdentities ?? [], static fn (array $row): bool => $row['projectId'] === $projectId);

            return new WorkshopConnection(
                $id,
                $bridge->cliVersion,
                $status->quiet,
                $url.'#agent-connection-'.$id,
                $bridge->label,
                null === $identity ? null : new WorkshopGitIdentity($identity['mode'], $identity['commitName'], $identity['pushLogin'], $identity['githubUserId'], $bridge->gitIdentitiesReportedAt),
                $repository['path'] ?? null,
                $repository['remoteUrl'] ?? null,
                null !== $bridge->gitIdentities,
                $status->loginExpired,
            );
        }, $bridges);
    }

    #[\Override]
    public function forOwnerOf(Project $project): array
    {
        $bridges = $this->bridges->findByOwner($project->owner);
        $statuses = $this->liveness->forOwner($project->owner, array_map(static fn ($bridge) => $bridge->id, $bridges));
        $url = $this->urls->generate('app_project_agents', ['id' => (string) $project->id]);

        return array_map(static function ($bridge) use ($statuses, $url): WorkshopConnection {
            $id = (string) $bridge->id;

            return new WorkshopConnection($id, $bridge->cliVersion, $statuses[$id]->quiet, $url.'#agent-connection-'.$id, $bridge->label, loginExpired: $statuses[$id]->loginExpired);
        }, $bridges);
    }
}
