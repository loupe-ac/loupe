<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Bridge\Contract\InitRuns;
use App\Module\Bridge\Service\InteractiveRuns;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Contract\ProjectMilestones;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class ProjectMilestoneApiTest extends WebTestCase
{
    use BridgeScenario;

    public function test_a_heartbeat_reaches_bridge_connected_for_each_followed_project_that_is_not_set_up(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $owner = $this->user($this->em(), 'milestone-heartbeat@example.com');
        $fresh = $this->project($this->em(), $owner, 'Milestone fresh');
        $done = $this->project($this->em(), $owner, 'Milestone done');
        $unfollowed = $this->project($this->em(), $owner, 'Milestone unfollowed');
        $this->em()->getConnection()->executeStatement('UPDATE projects SET set_up = true WHERE id = ?', [(string) $done->id]);

        $this->heartbeat($client, $this->agentToken($client, $owner), [(string) $fresh->id, (string) $done->id]);

        self::assertResponseIsSuccessful();
        self::assertSame([ProjectMilestones::BRIDGE_CONNECTED], $this->keys($fresh));
        self::assertSame([], $this->keys($done), 'A set-up project writes nothing.');
        self::assertSame([], $this->keys($unfollowed));
    }

    public function test_a_heartbeat_does_not_reach_the_milestone_of_a_project_the_owner_does_not_own(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $owner = $this->user($this->em(), 'milestone-heartbeat-owner@example.com');
        $stranger = $this->user($this->em(), 'milestone-heartbeat-stranger@example.com');
        $foreign = $this->project($this->em(), $stranger, 'Milestone foreign');

        $this->heartbeat($client, $this->agentToken($client, $owner), [(string) $foreign->id]);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->keys($foreign));
    }

    public function test_a_finished_close_reaches_init_finished_for_the_open_and_the_closed_init_runs_of_the_session(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $owner = $this->user($this->em(), 'milestone-close@example.com');
        $open = $this->project($this->em(), $owner, 'Milestone open init');
        $closed = $this->project($this->em(), $owner, 'Milestone closed init');
        $sessionId = Uuid::v4();
        $this->startInit($open, $sessionId);
        $run = $this->startInit($closed, $sessionId);
        $this->interactiveRuns()->closeById($this->managed($closed), $run);

        $this->close($client, $sessionId, $this->agentToken($client, $owner), '{"finished":true}');

        self::assertResponseStatusCodeSame(200);
        self::assertSame([ProjectMilestones::INIT_FINISHED], $this->keys($open));
        self::assertSame([ProjectMilestones::INIT_FINISHED], $this->keys($closed));
    }

    public function test_a_finished_close_with_a_heartbeat_before_it_sets_the_project_up(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $owner = $this->user($this->em(), 'milestone-both@example.com');
        $project = $this->project($this->em(), $owner, 'Milestone both');
        $sessionId = Uuid::v4();
        $this->startInit($project, $sessionId);
        $raw = $this->agentToken($client, $owner);

        $this->heartbeat($client, $raw, [(string) $project->id]);
        self::assertFalse($this->isSetUp($project));
        $this->close($client, $sessionId, $raw, '{"finished":true}');

        self::assertTrue($this->isSetUp($project));
    }

    public function test_a_close_without_finished_writes_no_milestone(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $owner = $this->user($this->em(), 'milestone-unfinished@example.com');
        $project = $this->project($this->em(), $owner, 'Milestone unfinished');
        $sessionId = Uuid::v4();
        $this->startInit($project, $sessionId);
        $raw = $this->agentToken($client, $owner);

        $this->close($client, $sessionId, $raw, null);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['closed' => 1], json_decode((string) $client->getResponse()->getContent(), true));

        $second = Uuid::v4();
        $this->startInit($project, $second);
        $this->close($client, $second, $raw, '{"finished":false}');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['closed' => 1], json_decode((string) $client->getResponse()->getContent(), true));

        self::assertSame([], $this->keys($project));
    }

    public function test_a_finished_close_of_a_session_with_no_init_run_writes_nothing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $owner = $this->user($this->em(), 'milestone-no-init@example.com');
        $project = $this->project($this->em(), $owner, 'Milestone no init');
        $sessionId = Uuid::v4();
        $this->interactiveRuns()->open($project, Uuid::v7(), 1, $sessionId, 'design');

        $this->close($client, $sessionId, $this->agentToken($client, $owner), '{"finished":true}');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['closed' => 1], json_decode((string) $client->getResponse()->getContent(), true));
        self::assertSame([], $this->keys($project));
    }

    public function test_a_finished_close_ignores_the_init_run_of_another_owner(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $owner = $this->user($this->em(), 'milestone-close-owner@example.com');
        $stranger = $this->user($this->em(), 'milestone-close-stranger@example.com');
        $foreign = $this->project($this->em(), $stranger, 'Milestone foreign init');
        $sessionId = Uuid::v4();
        $this->startInit($foreign, $sessionId);

        $this->close($client, $sessionId, $this->agentToken($client, $owner), '{"finished":true}');

        self::assertSame([], $this->keys($foreign));
    }

    public function test_a_finished_value_that_is_no_boolean_answers_422_and_writes_nothing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $owner = $this->user($this->em(), 'milestone-invalid@example.com');
        $project = $this->project($this->em(), $owner, 'Milestone invalid');
        $sessionId = Uuid::v4();
        $this->startInit($project, $sessionId);
        $raw = $this->agentToken($client, $owner);

        foreach (['"yes"', '1', 'null', '[]'] as $value) {
            $this->close($client, $sessionId, $raw, '{"finished":'.$value.'}');

            self::assertResponseStatusCodeSame(422, $value);
            self::assertStringContainsString('invalid_finished', (string) $client->getResponse()->getContent());
        }
        self::assertSame([], $this->keys($project));
    }

    private function startInit(Project $project, Uuid $sessionId): Uuid
    {
        [$run] = $this->interactiveRuns()->recordLaunch($this->managed($project), new WorkSubject(WorkSubject::PROJECT, $project->requireId()), null, $sessionId, InitRuns::WORK_KIND, Uuid::v4());

        return $run->id ?? throw new \LogicException('A stored run has an id.');
    }

    private function managed(Project $project): Project
    {
        return $this->em()->find(Project::class, $project->requireId()) ?? throw new \LogicException('The project is gone.');
    }

    private function interactiveRuns(): InteractiveRuns
    {
        $runs = self::getContainer()->get(InteractiveRuns::class);
        self::assertInstanceOf(InteractiveRuns::class, $runs);

        return $runs;
    }

    /** @return list<string> */
    private function keys(Project $project): array
    {
        return array_values(array_map(strval(...), $this->em()->getConnection()->fetchFirstColumn('SELECT "key" FROM project_milestones WHERE project_id = ? ORDER BY "key"', [(string) $project->id])));
    }

    private function isSetUp(Project $project): bool
    {
        return (bool) $this->em()->getConnection()->fetchOne('SELECT set_up FROM projects WHERE id = ?', [(string) $project->id]);
    }

    private function close(KernelBrowser $client, Uuid $sessionId, string $raw, ?string $body): void
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$raw, 'HTTP_ACCEPT' => 'application/json'];
        if (null !== $body) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        $client->request(Request::METHOD_POST, '/api/v1/bridge/interactive-sessions/'.$sessionId->toRfc4122().'/close', server: $server, content: $body);
    }

    /** @param list<string> $projects */
    private function heartbeat(KernelBrowser $client, string $raw, array $projects): void
    {
        $client->request(Request::METHOD_PUT, '/api/v1/bridge/bridges/'.Uuid::v4()->toRfc4122().'/heartbeat', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode(['projects' => $projects, 'cliVersion' => '1.0.0'], \JSON_THROW_ON_ERROR));
    }
}
