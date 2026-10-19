<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\GitHub\Repository\GitHubUserConnectionRepository;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Dev preview: gives the owner of a project six fake bridges, each reporting a
 * different git identity for it, so the Git identity panel can show every state.
 */
final readonly class SeedGitIdentitiesHandler
{
    private const string NAMESPACE = '6f0c1c1e-3c0a-4f0e-9a52-1d3b7a6f9e10';

    public function __construct(
        private ProjectRepository $projects,
        private BridgeRepository $bridges,
        private GitHubUserConnectionRepository $gitHubUserConnections,
        private EntityManagerInterface $em,
    ) {
    }

    /** @return list<string> the names of the bridges, one per state */
    public function __invoke(SeedGitIdentitiesCommand $command): array
    {
        $project = Uuid::isValid($command->projectId) ? $this->projects->find($command->projectId) : null;
        if (null === $project) {
            throw new DomainErrors(['project' => 'No project has this id.']);
        }

        $project->agentGitHubLogin ??= 'seed-agent';
        $ownerConnection = $this->gitHubUserConnections->findSummaryByUser($project->owner);
        $now = new \DateTimeImmutable();
        $rows = [
            'seed-app' => ['mode' => 'app_token', 'commitName' => 'loupe[bot]', 'pushLogin' => 'loupe[bot]', 'githubUserId' => null],
            'seed-agent' => ['mode' => 'agent_account', 'commitName' => 'Seed Agent', 'pushLogin' => $project->agentGitHubLogin, 'githubUserId' => null],
            'seed-separate' => ['mode' => 'machine', 'commitName' => 'Sam Separate', 'pushLogin' => $project->agentGitHubLogin, 'githubUserId' => 2_000_001],
            'seed-owner' => ['mode' => 'machine', 'commitName' => 'Owner Laptop', 'pushLogin' => $ownerConnection->login ?? 'seed-owner', 'githubUserId' => $ownerConnection?->githubUserId],
            'seed-unknown' => ['mode' => 'machine', 'commitName' => 'Unknown Box', 'pushLogin' => '', 'githubUserId' => null],
            'seed-older-cli' => null,
        ];

        foreach ($rows as $name => $row) {
            $id = Uuid::v5(Uuid::fromString(self::NAMESPACE), $project->id.':'.$name);
            $bridge = $this->bridges->findOneByOwnerAndId($project->owner, $id);
            if (null === $bridge) {
                $bridge = new Bridge($project->owner, $id, [(string) $project->id], 'seed', $now);
                $bridge->name = $name;
                $this->em->persist($bridge);
            }
            $bridge->lastSeenAt = $now;
            $bridge->projects = [(string) $project->id];
            $bridge->gitIdentities = null === $row ? null : [['projectId' => (string) $project->id] + $row];
            $bridge->gitIdentitiesReportedAt = null === $row ? null : $now;
        }
        $this->em->flush();

        return array_keys($rows);
    }
}
