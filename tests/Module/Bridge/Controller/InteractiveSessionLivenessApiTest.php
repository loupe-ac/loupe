<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Service\InteractiveRuns;
use App\Module\Bridge\ValueObject\WorkerRunCloseReason;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\AgentCredential;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class InteractiveSessionLivenessApiTest extends WebTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-10-28 12:00:00';

    public function test_the_exit_hook_endpoint_closes_the_session_in_every_project_of_the_token_owner(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $owner = $this->user($this->em(), 'session-close-owner@example.com');
        $stranger = $this->user($this->em(), 'session-close-stranger@example.com');
        $first = $this->project($this->em(), $owner, 'Session close one');
        $second = $this->project($this->em(), $owner, 'Session close two');
        $foreign = $this->project($this->em(), $stranger, 'Session close foreign');
        $sessionId = Uuid::v4();
        $one = $this->open($first, $sessionId);
        $two = $this->open($second, $sessionId);
        $other = $this->open($foreign, $sessionId);

        $this->post($client, $sessionId->toRfc4122(), $this->agentToken($client, $owner));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['closed' => 2], json_decode((string) $client->getResponse()->getContent(), true));
        self::assertSame(WorkerRunCloseReason::SessionEnded, $this->reload($one)->closeReason);
        self::assertSame(WorkerRunState::Closed, $this->reload($two)->state);
        self::assertSame(WorkerRunState::Running, $this->reload($other)->state);
    }

    public function test_the_endpoint_needs_an_agent_token_and_a_session_id(): void
    {
        $client = static::createClient();
        $owner = $this->user($this->em(), 'session-close-auth@example.com');
        $project = $this->project($this->em(), $owner, 'Session close auth');

        $this->post($client, Uuid::v4()->toRfc4122(), 'nonsense');
        self::assertResponseStatusCodeSame(401);

        $this->post($client, Uuid::v4()->toRfc4122(), AgentCredential::tokenFor(static::getContainer(), $owner, 'mcp', $project));
        self::assertResponseStatusCodeSame(403);

        $this->post($client, 'not-a-uuid', $this->agentToken($client, $owner));
        self::assertResponseStatusCodeSame(422);
    }

    public function test_the_heartbeat_names_the_launched_sessions_and_applies_the_readings(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('clock', new MockClock(self::NOW));
        $owner = $this->user($this->em(), 'session-heartbeat-owner@example.com');
        $project = $this->project($this->em(), $owner, 'Session heartbeat');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = Uuid::v4();
        $otherBridge = Uuid::v4();
        $alive = Uuid::v4();
        $gone = Uuid::v4();
        $young = Uuid::v4();
        $foreign = Uuid::v4();
        $aliveRun = $this->open($project, $alive, $bridgeId);
        $goneRun = $this->open($project, $gone, $bridgeId);
        $youngRun = $this->open($project, $young, $bridgeId);
        $foreignRun = $this->open($project, $foreign, $otherBridge);
        $this->startedAt($aliveRun, '2026-10-28 11:00:00');
        $this->startedAt($goneRun, '2026-10-28 11:00:00');
        $this->startedAt($foreignRun, '2026-10-28 11:00:00');
        $this->startedAt($youngRun, '2026-10-28 11:59:00');

        $this->heartbeat($client, $bridgeId, $raw, []);
        $names = $this->reply($client)['watchSessions'];
        self::assertIsArray($names);
        sort($names);
        $expected = [$alive->toRfc4122(), $gone->toRfc4122(), $young->toRfc4122()];
        sort($expected);
        self::assertSame($expected, $names);

        $this->heartbeat($client, $bridgeId, $raw, [
            ['sessionId' => $alive->toRfc4122(), 'alive' => true, 'transcriptAt' => null],
            ['sessionId' => $gone->toRfc4122(), 'alive' => false, 'transcriptAt' => null],
            ['sessionId' => $young->toRfc4122(), 'alive' => false, 'transcriptAt' => null],
            ['sessionId' => $foreign->toRfc4122(), 'alive' => false, 'transcriptAt' => null],
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::NOW, $this->reload($aliveRun)->lastActivityAt?->format('Y-m-d H:i:s'));
        self::assertSame(WorkerRunState::Running, $this->reload($aliveRun)->state);
        self::assertSame(WorkerRunCloseReason::SessionGone, $this->reload($goneRun)->closeReason);
        self::assertSame(WorkerRunState::Running, $this->reload($youngRun)->state, 'a run younger than two minutes stays open');
        self::assertSame(WorkerRunState::Running, $this->reload($foreignRun)->state, 'a reading for the session of another bridge changes nothing');
        self::assertNotContains($gone->toRfc4122(), $this->reply($client)['watchSessions']);
    }

    public function test_the_heartbeat_watches_a_codex_session_for_its_transcript_and_never_closes_it_from_a_process_check(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('clock', new MockClock(self::NOW));
        $owner = $this->user($this->em(), 'session-codex@example.com');
        $project = $this->project($this->em(), $owner, 'Session codex');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = Uuid::v4();
        $codex = Uuid::v4();
        $claude = Uuid::v4();
        $codexRun = $this->open($project, $codex, $bridgeId);
        $claudeRun = $this->open($project, $claude, $bridgeId);
        $this->startedAt($codexRun, '2026-10-28 11:00:00');
        $this->startedAt($claudeRun, '2026-10-28 11:00:00');
        $this->harness($codexRun, 'codex');
        $this->harness($claudeRun, 'claude-code');

        $this->heartbeat($client, $bridgeId, $raw, []);
        $watched = $this->reply($client)['watchSessions'];
        sort($watched);
        $expected = [$codex->toRfc4122(), $claude->toRfc4122()];
        sort($expected);
        self::assertSame($expected, $watched, 'a Codex session stays watched for its transcript time');

        $this->heartbeat($client, $bridgeId, $raw, [
            ['sessionId' => $codex->toRfc4122(), 'alive' => false, 'transcriptAt' => '2026-10-28T11:30:00+00:00'],
            ['sessionId' => $claude->toRfc4122(), 'alive' => false, 'transcriptAt' => null],
        ]);

        self::assertResponseStatusCodeSame(200);
        $codexAfter = $this->reload($codexRun);
        self::assertSame(WorkerRunState::Running, $codexAfter->state, 'a Codex process carries no session id, so the bridge cannot see it');
        self::assertSame('2026-10-28 11:30:00', $codexAfter->lastActivityAt?->format('Y-m-d H:i:s'));
        self::assertSame(WorkerRunCloseReason::SessionGone, $this->reload($claudeRun)->closeReason);
    }

    public function test_a_transcript_time_counts_as_activity_and_a_future_time_counts_as_now(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('clock', new MockClock(self::NOW));
        $owner = $this->user($this->em(), 'session-transcript@example.com');
        $project = $this->project($this->em(), $owner, 'Session transcript');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = Uuid::v4();
        $past = Uuid::v4();
        $future = Uuid::v4();
        $pastRun = $this->open($project, $past, $bridgeId);
        $futureRun = $this->open($project, $future, $bridgeId);

        $this->heartbeat($client, $bridgeId, $raw, [
            ['sessionId' => $past->toRfc4122(), 'alive' => null, 'transcriptAt' => '2026-10-28T09:30:00+00:00'],
            ['sessionId' => $future->toRfc4122(), 'alive' => null, 'transcriptAt' => '2026-10-29T09:30:00+00:00'],
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('2026-10-28 09:30:00', $this->reload($pastRun)->lastActivityAt?->format('Y-m-d H:i:s'));
        self::assertSame(self::NOW, $this->reload($futureRun)->lastActivityAt?->format('Y-m-d H:i:s'));
    }

    public function test_an_mcp_request_that_names_its_session_stamps_the_runs_of_its_bound_project(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('clock', new MockClock(self::NOW));
        $owner = $this->user($this->em(), 'session-stamp@example.com');
        $project = $this->project($this->em(), $owner, 'Session stamp');
        $other = $this->project($this->em(), $owner, 'Session stamp other');
        $sessionId = Uuid::v4();
        $mine = $this->open($project, $sessionId);
        $theirs = $this->open($other, $sessionId);
        $token = AgentCredential::tokenFor(static::getContainer(), $owner, 'mcp', $project);

        $client->request(Request::METHOD_POST, '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_X_LOUPE_SESSION' => $sessionId->toRfc4122(),
        ], content: '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::NOW, $this->reload($mine)->lastActivityAt?->format('Y-m-d H:i:s'));
        self::assertNull($this->reload($theirs)->lastActivityAt, 'the stamp stays inside the project of the token');
    }

    private function open(Project $project, Uuid $sessionId, ?Uuid $bridgeId = null): WorkerRun
    {
        $runs = static::getContainer()->get(InteractiveRuns::class);
        self::assertInstanceOf(InteractiveRuns::class, $runs);

        return $runs->open($project, Uuid::v7(), 1, $sessionId, 'design', $bridgeId);
    }

    private function startedAt(WorkerRun $run, string $at): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE bridge_worker_runs SET started_at = :at WHERE id = :id', ['at' => $at, 'id' => (string) $run->id]);
    }

    private function harness(WorkerRun $run, string $harness): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE bridge_worker_runs SET harness = :harness WHERE id = :id', ['harness' => $harness, 'id' => (string) $run->id]);
    }

    private function reload(WorkerRun $run): WorkerRun
    {
        $this->em()->clear();
        $reloaded = $this->em()->find(WorkerRun::class, $run->id);
        self::assertInstanceOf(WorkerRun::class, $reloaded);

        return $reloaded;
    }

    private function post(KernelBrowser $client, string $sessionId, string $raw): void
    {
        $client->request(Request::METHOD_POST, '/api/interactive-sessions/'.$sessionId.'/close', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
            'HTTP_ACCEPT' => 'application/json',
        ]);
    }

    /** @param list<array<string, mixed>> $readings */
    private function heartbeat(KernelBrowser $client, Uuid $bridgeId, string $raw, array $readings): void
    {
        $client->request(Request::METHOD_PUT, '/api/bridges/'.$bridgeId->toRfc4122().'/heartbeat', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode(['projects' => [], 'cliVersion' => '1.0.0', 'interactiveSessions' => $readings], \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function reply(KernelBrowser $client): array
    {
        $reply = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($reply);

        return $reply;
    }
}
