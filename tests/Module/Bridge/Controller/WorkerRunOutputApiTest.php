<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunOutputLog;
use App\Module\Project\Entity\Project;
use App\Outbox\AgentPush;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\AgentCredential;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

final class WorkerRunOutputApiTest extends WebTestCase
{
    use BridgeScenario;

    private Uuid $bridgeId;

    protected function setUp(): void
    {
        $this->bridgeId = Uuid::v7();
    }

    public function test_an_append_stores_the_text_and_answers_the_new_length(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'output-store@example.com');
        $project = $this->project($em, $owner, 'Output Store');
        $run = $this->keyedRun($project);
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $project, (string) $run->runKey, $raw, ['bridgeId' => (string) $this->bridgeId, 'offset' => 0, 'text' => "héllo\n"]);

        self::assertResponseStatusCodeSame(200);
        self::assertJsonStringEqualsJsonString('{"length":7,"capped":false}', (string) $client->getResponse()->getContent());

        $this->put($client, $project, (string) $run->runKey, $raw, ['bridgeId' => (string) $this->bridgeId, 'offset' => 7, 'text' => 'world']);

        self::assertJsonStringEqualsJsonString('{"length":12,"capped":false}', (string) $client->getResponse()->getContent());
        self::assertSame("héllo\nworld", $this->text($run));
    }

    public function test_a_wrong_offset_stores_nothing_and_answers_the_stored_length(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'output-offset@example.com');
        $project = $this->project($em, $owner, 'Output Offset');
        $run = $this->keyedRun($project);
        $raw = $this->agentToken($client, $owner);
        $this->put($client, $project, (string) $run->runKey, $raw, ['bridgeId' => (string) $this->bridgeId, 'offset' => 0, 'text' => 'abc']);

        $this->put($client, $project, (string) $run->runKey, $raw, ['bridgeId' => (string) $this->bridgeId, 'offset' => 1, 'text' => 'zzz']);

        self::assertResponseStatusCodeSame(200);
        self::assertJsonStringEqualsJsonString('{"length":3,"capped":false}', (string) $client->getResponse()->getContent());
        self::assertSame('abc', $this->text($run));
    }

    public function test_the_cap_is_reported(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'output-cap@example.com');
        $project = $this->project($em, $owner, 'Output Cap');
        $run = $this->keyedRun($project);
        $raw = $this->agentToken($client, $owner);
        $offset = 0;
        for ($i = 0; $i < 4; ++$i) {
            $this->put($client, $project, (string) $run->runKey, $raw, ['bridgeId' => (string) $this->bridgeId, 'offset' => $offset, 'text' => str_repeat('a', WorkerRunOutputLog::MAX_APPEND_BYTES)]);
            $offset += WorkerRunOutputLog::MAX_APPEND_BYTES;
        }
        self::assertJsonStringEqualsJsonString('{"length":'.WorkerRunOutputLog::MAX_BYTES.',"capped":false}', (string) $client->getResponse()->getContent());

        $this->put($client, $project, (string) $run->runKey, $raw, ['bridgeId' => (string) $this->bridgeId, 'offset' => $offset, 'text' => 'more']);

        self::assertJsonStringEqualsJsonString('{"length":'.WorkerRunOutputLog::MAX_BYTES.',"capped":true}', (string) $client->getResponse()->getContent());
    }

    public function test_a_text_over_the_request_limit_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'output-big@example.com');
        $project = $this->project($em, $owner, 'Output Big');
        $run = $this->keyedRun($project);

        $this->put($client, $project, (string) $run->runKey, $this->agentToken($client, $owner), ['bridgeId' => (string) $this->bridgeId, 'offset' => 0, 'text' => str_repeat('a', WorkerRunOutputLog::MAX_APPEND_BYTES + 1)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('', $this->text($run));
    }

    public function test_the_limit_counts_bytes_and_not_characters(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'output-bytes@example.com');
        $project = $this->project($em, $owner, 'Output Bytes');
        $run = $this->keyedRun($project);

        $this->put($client, $project, (string) $run->runKey, $this->agentToken($client, $owner), ['bridgeId' => (string) $this->bridgeId, 'offset' => 0, 'text' => str_repeat('€', (int) (WorkerRunOutputLog::MAX_APPEND_BYTES / 3) + 1)]);

        self::assertResponseStatusCodeSame(422);
    }

    public function test_an_invalid_body_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'output-invalid@example.com');
        $project = $this->project($em, $owner, 'Output Invalid');
        $run = $this->keyedRun($project);
        $raw = $this->agentToken($client, $owner);

        foreach ([
            ['offset' => 0, 'text' => 'a'],
            ['bridgeId' => 'not-a-uuid', 'offset' => 0, 'text' => 'a'],
            ['bridgeId' => (string) $this->bridgeId, 'offset' => -1, 'text' => 'a'],
            ['bridgeId' => (string) $this->bridgeId, 'text' => 'a'],
            ['bridgeId' => (string) $this->bridgeId, 'offset' => 0],
        ] as $body) {
            $this->put($client, $project, (string) $run->runKey, $raw, $body);
            self::assertResponseStatusCodeSame(422);
        }

        $client->request(Request::METHOD_PUT, '/api/projects/'.$project->id.'/worker-runs/'.$run->runKey.'/output', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: '{"bridgeId":"'.$this->bridgeId.'","offset":0,"text":"'."\xff".'"}');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('', $this->text($run));
    }

    public function test_a_run_key_that_is_no_uuid_is_refused_with_a_code(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'output-run-id@example.com');
        $project = $this->project($em, $owner, 'Output Run Id');

        $this->put($client, $project, 'nope', $this->agentToken($client, $owner), ['bridgeId' => (string) $this->bridgeId, 'offset' => 0, 'text' => 'a']);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonStringEqualsJsonString('{"error":"invalid_run_id"}', (string) $client->getResponse()->getContent());
    }

    public function test_an_unknown_project_is_refused_with_a_code(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'output-no-project@example.com');

        $client->request(Request::METHOD_PUT, '/api/projects/'.Uuid::v4().'/worker-runs/'.Uuid::v4().'/output', server: $this->headers($this->agentToken($client, $owner)), content: $this->json(['bridgeId' => (string) $this->bridgeId, 'offset' => 0, 'text' => 'a']));

        self::assertResponseStatusCodeSame(404);
        self::assertJsonStringEqualsJsonString('{"error":"project_not_found"}', (string) $client->getResponse()->getContent());
    }

    public function test_an_unknown_run_is_refused_with_a_code(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'output-no-run@example.com');
        $project = $this->project($em, $owner, 'Output No Run');

        $this->put($client, $project, (string) Uuid::v4(), $this->agentToken($client, $owner), ['bridgeId' => (string) $this->bridgeId, 'offset' => 0, 'text' => 'a']);

        self::assertResponseStatusCodeSame(404);
        self::assertJsonStringEqualsJsonString('{"error":"run_not_found"}', (string) $client->getResponse()->getContent());
    }

    public function test_a_run_of_another_bridge_is_refused_and_stores_nothing(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'output-bridge@example.com');
        $project = $this->project($em, $owner, 'Output Bridge');
        $run = $this->keyedRun($project);

        $this->put($client, $project, (string) $run->runKey, $this->agentToken($client, $owner), ['bridgeId' => (string) Uuid::v7(), 'offset' => 0, 'text' => 'a']);

        self::assertResponseStatusCodeSame(404);
        self::assertJsonStringEqualsJsonString('{"error":"run_not_found"}', (string) $client->getResponse()->getContent());
        self::assertSame('', $this->text($run));
    }

    public function test_a_stranger_cannot_append_to_the_run(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'output-owner@example.com');
        $stranger = $this->user($em, 'output-stranger@example.com');
        $project = $this->project($em, $owner, 'Output Owned');
        $run = $this->keyedRun($project);

        $this->put($client, $project, (string) $run->runKey, $this->agentToken($client, $stranger), ['bridgeId' => (string) $this->bridgeId, 'offset' => 0, 'text' => 'a']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('', $this->text($run));
    }

    public function test_a_request_with_no_token_is_unauthorized(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'output-anon@example.com'), 'Output Anon');
        $run = $this->keyedRun($project);

        $client->request(Request::METHOD_PUT, '/api/projects/'.$project->id.'/worker-runs/'.$run->runKey.'/output', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: $this->json(['bridgeId' => (string) $this->bridgeId, 'offset' => 0, 'text' => 'a']));

        self::assertResponseStatusCodeSame(401);
    }

    public function test_a_widget_token_is_forbidden(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'output-widget@example.com');
        $project = $this->project($em, $owner, 'Output Widget');
        $run = $this->keyedRun($project);
        $raw = AgentCredential::tokenFor(static::getContainer(), $owner, 'site-review', $project);

        $this->put($client, $project, (string) $run->runKey, $raw, ['bridgeId' => (string) $this->bridgeId, 'offset' => 0, 'text' => 'a']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('', $this->text($run));
    }

    public function test_the_endpoint_is_gone_when_the_agent_push_flag_is_off(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'output-flag@example.com');
        $project = $this->project($em, $owner, 'Output Flag');
        $run = $this->keyedRun($project);
        $raw = $this->agentToken($client, $owner);
        $em->getConnection()->executeStatement("UPDATE feature_flag SET value = 'false' WHERE name = ?", [AgentPush::FLAG]);

        $this->put($client, $project, (string) $run->runKey, $raw, ['bridgeId' => (string) $this->bridgeId, 'offset' => 0, 'text' => 'a']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('', $this->text($run));
    }

    public function test_appends_have_a_budget_of_their_own(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('limiter.agent_run_output', new RateLimiterFactory(
            ['id' => 'agent_run_output', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        $em = $this->em();
        $owner = $this->user($em, 'output-limit@example.com');
        $project = $this->project($em, $owner, 'Output Limit');
        $run = $this->keyedRun($project);
        $raw = $this->agentToken($client, $owner);
        $body = ['bridgeId' => (string) $this->bridgeId, 'offset' => 0, 'text' => ''];

        $this->put($client, $project, (string) $run->runKey, $raw, $body);
        self::assertResponseStatusCodeSame(200);

        $this->put($client, $project, (string) $run->runKey, $raw, $body);
        self::assertResponseStatusCodeSame(429);
    }

    public function test_appends_do_not_spend_the_run_report_budget(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('limiter.agent_worker_runs', new RateLimiterFactory(
            ['id' => 'agent_worker_runs', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        $em = $this->em();
        $owner = $this->user($em, 'output-shared@example.com');
        $project = $this->project($em, $owner, 'Output Shared');
        $run = $this->keyedRun($project);
        $raw = $this->agentToken($client, $owner);

        for ($i = 0; $i < 3; ++$i) {
            $this->put($client, $project, (string) $run->runKey, $raw, ['bridgeId' => (string) $this->bridgeId, 'offset' => 0, 'text' => '']);
            self::assertResponseStatusCodeSame(200);
        }
    }

    private function keyedRun(Project $project): WorkerRun
    {
        return $this->seedRun($this->em(), $project, bridgeId: $this->bridgeId, runKey: Uuid::v4());
    }

    /** @param array<string, mixed> $body */
    private function put(KernelBrowser $client, Project $project, string $runKey, string $raw, array $body): void
    {
        $client->request(Request::METHOD_PUT, '/api/projects/'.$project->id.'/worker-runs/'.$runKey.'/output', server: $this->headers($raw), content: $this->json($body));
    }

    /** @return array<string, string> */
    private function headers(string $raw): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];
    }

    /** @param array<string, mixed> $body */
    private function json(array $body): string
    {
        return json_encode($body, \JSON_THROW_ON_ERROR);
    }

    private function text(WorkerRun $run): string
    {
        return (string) $this->em()->getConnection()->fetchOne(
            "SELECT COALESCE(string_agg(text, '' ORDER BY start_offset), '') FROM bridge_worker_run_output_chunks WHERE run_id = :run",
            ['run' => (string) $run->id],
        );
    }
}
