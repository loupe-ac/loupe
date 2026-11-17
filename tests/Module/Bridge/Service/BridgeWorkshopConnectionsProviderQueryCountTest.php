<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Command\ListAgentsCommand;
use App\Module\Bridge\Command\ListAgentsHandler;
use App\Module\Bridge\Service\BridgeWorkshopConnectionsProvider;
use App\Module\Project\Entity\Project;
use App\Module\Project\Workshop\WorkshopConnection;
use App\Module\Project\Workshop\WorkshopGitIdentity;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Reader\FeatureFlagReaderInterface;

/** The workshop connections need the bridge rows and their liveness, so they cost two statements whatever the bridge count. */
final class BridgeWorkshopConnectionsProviderQueryCountTest extends KernelTestCase
{
    use BridgeScenario;

    private DebugDataHolder $queries;

    protected function setUp(): void
    {
        self::bootKernel();

        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $queries);
        $this->queries = $queries;

        $flags = self::getContainer()->get(FeatureFlagReaderInterface::class);
        self::assertInstanceOf(FeatureFlagReaderInterface::class, $flags);
        $flags->all();
    }

    public function test_one_bridge_and_three_bridges_cost_the_same_two_statements(): void
    {
        $one = $this->projectFollowedBy('connections-q-one@example.com', 1);
        $three = $this->projectFollowedBy('connections-q-three@example.com', 3);
        $this->em()->clear();

        [$forOne, $oneCount] = $this->connecting($one);
        [$forThree, $threeCount] = $this->connecting($three);

        // Guards: both calls returned their bridges, so a call that read nothing cannot pass.
        self::assertSame(1, $oneCount);
        self::assertSame(3, $threeCount);
        self::assertCount(2, $forOne, implode("\n", $forOne));
        self::assertCount(2, $forThree, implode("\n", $forThree));
    }

    public function test_the_connections_hold_the_values_of_the_agents_list_in_the_same_order(): void
    {
        $em = $this->em();
        $owner = $this->user($em, 'connections-same@example.com');
        $project = $this->project($em, $owner, 'Same');
        $other = $this->project($em, $owner, 'Other');
        $id = (string) $project->id;
        $now = new \DateTimeImmutable();

        $old = $this->seedBridge($em, $owner, Uuid::fromString('0199a0c4-5d3e-7b1a-8f00-00000000a001'), [$id], lastSeenAt: $now->modify('-2 hours'));
        $old->name = 'Old laptop';
        $old->repositories = [['projectId' => $id, 'path' => '/work/loupe', 'remoteUrl' => 'git@github.com:acme/loupe.git']];
        $old->gitIdentities = [['projectId' => $id, 'mode' => 'machine', 'commitName' => 'Riley', 'pushLogin' => 'riley', 'githubUserId' => 42]];
        $old->gitIdentitiesReportedAt = $now->modify('-3 hours');
        $fresh = $this->seedBridge($em, $owner, Uuid::fromString('0199a0c4-5d3e-7b1a-8f00-00000000a002'), [$id, (string) $other->id], 'c0ffee01', $now);
        $middle = $this->seedBridge($em, $owner, Uuid::fromString('0199a0c4-5d3e-7b1a-8f00-00000000a003'), [$id], lastSeenAt: $now->modify('-1 hour'));
        $this->seedBridge($em, $owner, Uuid::fromString('0199a0c4-5d3e-7b1a-8f00-00000000a004'), [(string) $other->id]);
        $em->clear();

        $connections = $this->provider()->forProject($this->reloaded($project));

        self::assertSame([(string) $fresh->id, (string) $middle->id, (string) $old->id], array_map(static fn (WorkshopConnection $c): string => $c->id, $connections));
        [$first, $second, $third] = $connections;
        self::assertSame('c0ffee01', $first->cliVersion);
        self::assertNull($first->gitIdentity);
        self::assertNull($first->repositoryPath);
        self::assertFalse($first->reportsGitIdentities);
        self::assertFalse($first->quiet);
        self::assertTrue($third->reportsGitIdentities);
        self::assertSame('Old laptop', $third->label);
        self::assertSame('/work/loupe', $third->repositoryPath);
        self::assertSame('git@github.com:acme/loupe.git', $third->repositoryRemoteUrl);
        self::assertInstanceOf(WorkshopGitIdentity::class, $third->gitIdentity);
        self::assertSame('machine', $third->gitIdentity->mode);
        self::assertSame('Riley', $third->gitIdentity->commitName);
        self::assertSame(42, $third->gitIdentity->githubUserId);
        self::assertSame('/projects/'.$id.'/agents#agent-connection-'.$middle->id, $second->url);

        // The full agents list is the reference: the slim read returns what it returned before.
        $reference = array_map(
            static fn ($connection): array => [(string) $connection->bridge->id, $connection->bridge->cliVersion, $connection->status->quiet, $connection->status->loginExpired, $connection->bridge->label],
            (self::getContainer()->get(ListAgentsHandler::class))(new ListAgentsCommand($this->reloaded($project)))->connections,
        );
        self::assertSame(
            $reference,
            array_map(static fn (WorkshopConnection $c): array => [$c->id, $c->cliVersion, $c->quiet, $c->loginExpired, $c->label], $connections),
        );
    }

    private function provider(): BridgeWorkshopConnectionsProvider
    {
        $provider = self::getContainer()->get(BridgeWorkshopConnectionsProvider::class);
        self::assertInstanceOf(BridgeWorkshopConnectionsProvider::class, $provider);

        return $provider;
    }

    private function reloaded(Project $stored): Project
    {
        return $this->em()->find(Project::class, $stored->id) ?? throw new \LogicException('The project is stored.');
    }

    /** @param non-empty-string $email */
    private function projectFollowedBy(string $email, int $bridges): Project
    {
        $em = $this->em();
        $owner = $this->user($em, $email);
        $project = $this->project($em, $owner, $email);
        for ($i = 0; $i < $bridges; ++$i) {
            $this->seedBridge($em, $owner, projects: [(string) $project->id]);
        }

        return $project;
    }

    /** @return array{list<string>, int} the statements, and the number of connections */
    private function connecting(Project $stored): array
    {
        $project = $this->reloaded($stored);

        $this->queries->reset();
        $connections = $this->provider()->forProject($project);

        $statements = [];
        foreach ($this->queries->getData() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                $statements[] = (string) $query['sql'];
            }
        }

        return [$statements, \count($connections)];
    }
}
