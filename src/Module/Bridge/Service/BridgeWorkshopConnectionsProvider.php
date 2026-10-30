<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Bridge\Command\ListAgentsCommand;
use App\Module\Bridge\Command\ListAgentsHandler;
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
        private ListAgentsHandler $agents,
        private BridgeRepository $bridges,
        private BridgeLiveness $liveness,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[\Override]
    public function forProject(Project $project): array
    {
        $url = $this->urls->generate('app_project_agents', ['id' => (string) $project->id]);
        $connections = [];
        foreach (($this->agents)(new ListAgentsCommand($project))->connections as $connection) {
            $id = (string) $connection->bridge->id;
            $repository = array_find($connection->bridge->repositories ?? [], static fn (array $row): bool => $row['projectId'] === (string) $project->id);
            $identity = array_find($connection->bridge->gitIdentities ?? [], static fn (array $row): bool => $row['projectId'] === (string) $project->id);
            $connections[] = new WorkshopConnection(
                $id,
                $connection->bridge->cliVersion,
                $connection->status->quiet,
                $url.'#agent-connection-'.$id,
                $connection->bridge->label,
                null === $identity ? null : new WorkshopGitIdentity($identity['mode'], $identity['commitName'], $identity['pushLogin'], $identity['githubUserId'], $connection->bridge->gitIdentitiesReportedAt),
                $repository['path'] ?? null,
                $repository['remoteUrl'] ?? null,
                null !== $connection->bridge->gitIdentities,
                $connection->status->loginExpired,
            );
        }

        return $connections;
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
