<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Api;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\ValueObject\BridgeCommandCause;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\WorkerRunCloseReason;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\ApiKeyCredential;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** The worker run and bridge routes of the public API. */
final class WorkerRunApiTest extends WebTestCase
{
    use BridgeScenario;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $owner;
    private Project $project;
    private Project $other;
    private Bridge $bridge;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = $this->em();
        $this->owner = $this->user($this->em, 'run-api-'.uniqid().'@example.com');
        $this->project = $this->project($this->em, $this->owner, 'Run API');
        $this->other = $this->project($this->em, $this->owner, 'Run API other');
        $this->bridge = $this->seedBridge($this->em, $this->owner, projects: [(string) $this->project->id]);
        $this->bridge->capabilities = [Bridge::CAPABILITY_COMMANDS];
        $this->em->flush();
        $this->token = ApiKeyCredential::mint(static::getContainer(), $this->owner);
    }

    public function test_the_list_walks_newest_first_by_cursor_and_filters_by_state_and_card(): void
    {
        $old = $this->runIn(WorkerRunState::Failed, receivedAt: '2026-01-01 10:00:00', cardNumber: 3);
        $same = $this->runIn(WorkerRunState::Succeeded, receivedAt: '2026-01-02 10:00:00', cardNumber: 4);
        $sameToo = $this->runIn(WorkerRunState::Succeeded, receivedAt: '2026-01-02 10:00:00', cardNumber: 4);
        $newest = $this->runIn(WorkerRunState::Failed, receivedAt: '2026-01-03 10:00:00', cardNumber: 3);
        $this->runIn(WorkerRunState::Failed, project: $this->other, receivedAt: '2026-01-04 10:00:00');

        $this->send(Request::METHOD_GET, $this->runs().'?limit=2');
        self::assertResponseStatusCodeSame(200);
        $first = $this->json();
        self::assertSame((string) $newest->id, $first['items'][0]['runId']);
        self::assertCount(2, $first['items']);
        self::assertIsString($first['next']);

        $this->send(Request::METHOD_GET, $first['next']);
        $second = $this->json();
        $seen = array_column([...$first['items'], ...$second['items']], 'runId');
        self::assertCount(4, array_unique($seen));
        self::assertEqualsCanonicalizing([(string) $old->id, (string) $same->id, (string) $sameToo->id, (string) $newest->id], $seen);
        self::assertNull($second['next']);

        $this->send(Request::METHOD_GET, $this->runs().'?state=failed&card=3');
        self::assertEqualsCanonicalizing([(string) $old->id, (string) $newest->id], array_column($this->json()['items'], 'runId'));

        $this->send(Request::METHOD_GET, $this->runs().'?state=failed&state=succeeded&card=4');
        self::assertCount(2, $this->json()['items']);

        $this->send(Request::METHOD_GET, $this->runs().'?state[]=failed&state[]=succeeded');
        self::assertCount(4, $this->json()['items']);
    }

    public function test_a_row_carries_the_pending_command(): void
    {
        $run = $this->runIn(WorkerRunState::Failed);
        $command = $this->seedCommand($this->em, $run, kind: BridgeCommandKind::ResumeRun);

        $this->send(Request::METHOD_GET, $this->runs());

        $row = $this->json()['items'][0];
        self::assertSame(['commandId' => (string) $command->id, 'kind' => 'resume-run', 'state' => 'pending'], $row['pendingCommand']);
    }

    public function test_bad_filters_cursors_and_limits_are_refused(): void
    {
        foreach (['?state=nope', '?card=abc', '?bridge=x', '?endedAfter=yesterday', '?endedBefore=2026-02-31', '?limit=0'] as $query) {
            $this->send(Request::METHOD_GET, $this->runs().$query);
            self::assertResponseStatusCodeSame(422, $query);
            self::assertSame('validation_failed', $this->json()['code']);
        }

        $this->send(Request::METHOD_GET, $this->runs().'?cursor=nonsense');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_cursor', $this->json()['code']);

        $first = $this->runIn(WorkerRunState::Failed);
        $this->runIn(WorkerRunState::Failed, receivedAt: '2025-01-01 10:00:00');
        $this->send(Request::METHOD_GET, $this->runs().'?limit=1');
        $this->send(Request::METHOD_GET, $this->json()['next'].'&state=failed');
        self::assertResponseStatusCodeSame(400);
        unset($first);
    }

    public function test_a_run_reads_with_its_series_and_its_commands(): void
    {
        $first = $this->runIn(WorkerRunState::Failed, receivedAt: '2026-01-01 10:00:00');
        $second = $this->runIn(WorkerRunState::Running, receivedAt: '2026-01-02 10:00:00');
        $this->em->getConnection()->update('bridge_worker_runs', ['continues_run_id' => (string) $first->id], ['id' => (string) $second->id]);
        $this->em->clear();
        $this->seedCommand($this->em, $this->em->find(WorkerRun::class, $first->id) ?? throw new \LogicException('The run is gone.'), BridgeCommandState::Done, kind: BridgeCommandKind::ResumeRun);

        $this->send(Request::METHOD_GET, $this->runs().'/'.$second->id);

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame((string) $second->id, $body['run']['runId']);
        self::assertSame((string) $first->id, $body['run']['continuesRunId']);
        self::assertArrayHasKey('output', $body['run']);
        self::assertSame([(string) $first->id, (string) $second->id], array_column($body['series'], 'runId'));
        self::assertCount(1, $body['commands']);
        self::assertSame('resume-run', $body['commands'][0]['kind']);
    }

    public function test_a_run_of_another_project_is_not_found_on_every_run_route(): void
    {
        $foreign = $this->runIn(WorkerRunState::Failed, project: $this->other);
        $missing = (string) Uuid::v7();

        foreach ([[Request::METHOD_GET, ''], [Request::METHOD_GET, '/tool-calls'], [Request::METHOD_GET, '/host-samples'], [Request::METHOD_POST, '/stop'], [Request::METHOD_POST, '/resume'], [Request::METHOD_DELETE, '/pending-command']] as [$method, $suffix]) {
            foreach ([(string) $foreign->id, $missing] as $id) {
                $this->send($method, $this->runs().'/'.$id.$suffix, Request::METHOD_POST === $method ? '{}' : '');
                self::assertResponseStatusCodeSame(404, $method.' '.$suffix);
                self::assertSame('run_not_found', $this->json()['code']);
            }
        }
        self::assertSame(0, $this->countCommands($this->em));
    }

    public function test_a_stranger_gets_project_not_found_and_no_key_is_refused(): void
    {
        $this->runIn(WorkerRunState::Failed);
        $stranger = ApiKeyCredential::mint(static::getContainer(), $this->user($this->em, 'run-api-stranger-'.uniqid().'@example.com'));

        $this->send(Request::METHOD_GET, $this->runs(), '', $stranger);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('project_not_found', $this->json()['code']);

        $this->send(Request::METHOD_GET, $this->runs(), '', null);
        self::assertResponseStatusCodeSame(401);
    }

    public function test_the_tool_calls_walk_by_sequence(): void
    {
        $run = $this->runIn(WorkerRunState::Succeeded);
        foreach ([1, 2, 3] as $seq) {
            $this->seedToolCall($run, $seq, 1 === $seq ? 'Bash' : 'Read');
        }

        $this->send(Request::METHOD_GET, $this->runs().'/'.$run->id.'/tool-calls?limit=2');
        $first = $this->json();
        self::assertSame([1, 2], array_column($first['items'], 'seq'));
        self::assertSame('shell', $first['items'][0]['kind']);

        $this->send(Request::METHOD_GET, $first['next']);
        $second = $this->json();
        self::assertSame([3], array_column($second['items'], 'seq'));
        self::assertNull($second['next']);
    }

    public function test_the_host_samples_walk_oldest_first_inside_the_window_of_the_run(): void
    {
        $run = $this->runIn(WorkerRunState::Succeeded);
        foreach (['2026-01-01 09:59:00', '2026-01-01 10:01:00', '2026-01-01 10:02:00', '2026-01-01 10:03:00', '2026-01-01 10:30:00'] as $at) {
            $this->seedHostSample($this->bridge, $at);
        }

        $this->send(Request::METHOD_GET, $this->runs().'/'.$run->id.'/host-samples?limit=2');
        $first = $this->json();
        self::assertSame(['2026-01-01T10:01:00+00:00', '2026-01-01T10:02:00+00:00'], array_column($first['items'], 'sampledAt'));
        self::assertEqualsWithDelta(20.0, $first['items'][0]['meanCpuPct'], 0.001);
        self::assertNull($first['note']);

        $this->send(Request::METHOD_GET, $first['next']);
        $second = $this->json();
        self::assertSame(['2026-01-01T10:03:00+00:00'], array_column($second['items'], 'sampledAt'));
        self::assertNull($second['next']);
    }

    public function test_the_project_bridge_list_carries_the_open_runs_and_the_account_list_every_bridge(): void
    {
        $open = $this->runIn(WorkerRunState::Running);
        $elsewhere = $this->seedBridge($this->em, $this->owner, projects: [(string) $this->other->id]);

        $this->send(Request::METHOD_GET, '/api/v1/projects/'.$this->project->id.'/bridges');
        self::assertResponseStatusCodeSame(200);
        $items = $this->json()['items'];
        self::assertSame([(string) $this->bridge->id], array_column($items, 'bridgeId'));
        self::assertSame([(string) $open->id], array_column($items[0]['openRuns'], 'runId'));
        self::assertSame([], $items[0]['refusedRequests']);

        $this->send(Request::METHOD_GET, '/api/v1/bridges?limit=1');
        $first = $this->json();
        self::assertCount(1, $first['items']);
        self::assertIsString($first['next']);
        $this->send(Request::METHOD_GET, $first['next']);
        $second = $this->json();
        $ids = [$first['items'][0]['bridgeId'], $second['items'][0]['bridgeId']];
        self::assertEqualsCanonicalizing([(string) $this->bridge->id, (string) $elsewhere->id], $ids);
        $sorted = $ids;
        sort($sorted);
        self::assertSame($sorted, $ids);
        $projectIds = [(string) $this->bridge->id => [(string) $this->project->id], (string) $elsewhere->id => [(string) $this->other->id]];
        self::assertSame($projectIds[$ids[0]], $first['items'][0]['projectIds']);
        self::assertSame($projectIds[$ids[1]], $second['items'][0]['projectIds']);
    }

    public function test_a_credential_bound_to_one_project_sees_only_the_bridges_that_serve_it(): void
    {
        $this->seedBridge($this->em, $this->owner, projects: [(string) $this->other->id, (string) $this->project->id]);
        $this->seedBridge($this->em, $this->owner, projects: [(string) $this->other->id]);
        $bound = AgentCredential::tokenFor(static::getContainer(), $this->owner, 'public mcp', $this->project);

        $this->send(Request::METHOD_GET, '/api/v1/bridges', '', $bound);

        self::assertResponseStatusCodeSame(200);
        $items = $this->json()['items'];
        self::assertCount(2, $items);
        foreach ($items as $item) {
            self::assertSame([(string) $this->project->id], $item['projectIds']);
        }
    }

    public function test_a_stop_by_a_key_asks_the_bridge_and_records_the_key(): void
    {
        $run = $this->runIn(WorkerRunState::Running);

        $this->send(Request::METHOD_POST, $this->runs().'/'.$run->id.'/stop', '{"reason":"cost"}');

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame('stopped', $body['outcome']);
        $command = $this->onlyCommand();
        self::assertSame((string) $command->id, $body['commandId']);
        self::assertSame(BridgeCommandKind::StopRun, $command->kind);
        self::assertSame('cost', $command->reason);
        self::assertSame(BridgeCommandCause::ApiKey, $command->cause);
        self::assertNotNull($command->apiKeyId);
        self::assertSame('Test key', $command->apiKeyName);
    }

    public function test_a_stop_takes_no_body(): void
    {
        $run = $this->runIn(WorkerRunState::Running);

        $this->send(Request::METHOD_POST, $this->runs().'/'.$run->id.'/stop');

        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->onlyCommand()->reason);
    }

    public function test_a_stop_of_a_run_that_cannot_stop_or_waits_already_is_a_conflict(): void
    {
        $done = $this->runIn(WorkerRunState::Succeeded);
        $this->send(Request::METHOD_POST, $this->runs().'/'.$done->id.'/stop', '{}');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('run_not_stoppable', $this->json()['code']);

        $running = $this->runIn(WorkerRunState::Running);
        $this->seedCommand($this->em, $running);
        $this->send(Request::METHOD_POST, $this->runs().'/'.$running->id.'/stop', '{}');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('command_pending', $this->json()['code']);

        $this->send(Request::METHOD_POST, $this->runs().'/'.$running->id.'/stop', '{"reason":"'.str_repeat('x', 1001).'"}');
        self::assertResponseStatusCodeSame(422);
    }

    public function test_a_stop_closes_an_interactive_session_and_records_the_key(): void
    {
        $run = $this->seedRun($this->em, $this->project, state: WorkerRunState::Running, runKey: Uuid::v7(), kind: WorkerRunKind::Interactive);

        $this->send(Request::METHOD_POST, $this->runs().'/'.$run->id.'/stop', '{}');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['outcome' => 'closed', 'commandId' => null], $this->json());
        $this->em->clear();
        $closed = $this->em->find(WorkerRun::class, $run->id);
        self::assertInstanceOf(WorkerRun::class, $closed);
        self::assertSame(WorkerRunCloseReason::ApiKey, $closed->closeReason);
        self::assertSame('Test key', $closed->closedApiKeyName);
        self::assertSame(0, $this->countCommands($this->em));
    }

    public function test_a_resume_by_a_key_asks_the_bridge_and_records_the_key(): void
    {
        $run = $this->runIn(WorkerRunState::Failed);

        $this->send(Request::METHOD_POST, $this->runs().'/'.$run->id.'/resume');

        self::assertResponseStatusCodeSame(200);
        $command = $this->onlyCommand();
        self::assertSame(['commandId' => (string) $command->id], $this->json());
        self::assertSame(BridgeCommandKind::ResumeRun, $command->kind);
        self::assertSame(BridgeCommandCause::ApiKey, $command->cause);
        self::assertSame('Test key', $command->apiKeyName);
    }

    public function test_a_resume_of_a_run_that_cannot_resume_is_a_conflict(): void
    {
        $run = $this->runIn(WorkerRunState::Succeeded);

        $this->send(Request::METHOD_POST, $this->runs().'/'.$run->id.'/resume');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('run_not_resumable', $this->json()['code']);
        self::assertSame(0, $this->countCommands($this->em));
    }

    public function test_a_batch_resume_answers_a_row_for_each_run_and_records_the_key(): void
    {
        $good = $this->runIn(WorkerRunState::Failed);
        $done = $this->runIn(WorkerRunState::Succeeded);
        $foreign = $this->runIn(WorkerRunState::Failed, project: $this->other);
        $missing = (string) Uuid::v7();

        $this->send(Request::METHOD_POST, $this->runs().'/resume', json_encode(['runIds' => [(string) $good->id, (string) $done->id, (string) $foreign->id, $missing]], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(200);
        $results = $this->json()['results'];
        self::assertSame(['resumed', 'refused', 'refused', 'refused'], array_column($results, 'outcome'));
        self::assertSame([(string) $good->id, (string) $done->id, (string) $foreign->id, $missing], array_column($results, 'runId'));
        self::assertSame('not-resumable', $results[1]['code']);
        self::assertSame('not-found', $results[2]['code']);
        self::assertSame('not-found', $results[3]['code']);
        $command = $this->onlyCommand();
        self::assertSame((string) $command->id, $results[0]['commandId']);
        self::assertSame(BridgeCommandCause::ApiKey, $command->cause);
        self::assertSame('Test key', $command->apiKeyName);
    }

    public function test_a_batch_resume_refuses_an_empty_list_a_long_list_and_a_bad_id(): void
    {
        foreach (['{}', '{"runIds":[]}', '{"runIds":null}', '{"runIds":["nope"]}', json_encode(['runIds' => array_fill(0, 51, (string) Uuid::v7())], \JSON_THROW_ON_ERROR)] as $body) {
            $this->send(Request::METHOD_POST, $this->runs().'/resume', $body);
            self::assertResponseStatusCodeSame(422, $body);
        }
        self::assertSame(0, $this->countCommands($this->em));
    }

    public function test_a_cancel_withdraws_the_waiting_command_and_records_the_key_apart_from_the_requester(): void
    {
        $run = $this->runIn(WorkerRunState::Failed);
        $waiting = $this->seedCommand($this->em, $run, kind: BridgeCommandKind::ResumeRun, requestedBy: $this->owner);

        $this->send(Request::METHOD_DELETE, $this->runs().'/'.$run->id.'/pending-command');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['commandId' => (string) $waiting->id], $this->json());
        $this->em->clear();
        $stored = $this->em->find(BridgeCommand::class, $waiting->id);
        self::assertInstanceOf(BridgeCommand::class, $stored);
        self::assertSame(BridgeCommandState::Cancelled, $stored->state);
        self::assertNull($stored->apiKeyId);
        self::assertSame('Test key', $stored->cancelledApiKeyName);
    }

    public function test_a_cancel_with_nothing_pending_is_a_conflict(): void
    {
        $run = $this->runIn(WorkerRunState::Failed);

        $this->send(Request::METHOD_DELETE, $this->runs().'/'.$run->id.'/pending-command');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('nothing_pending', $this->json()['code']);
    }

    public function test_a_credential_bound_to_another_project_cannot_stop_a_run(): void
    {
        $run = $this->runIn(WorkerRunState::Running);
        $bound = AgentCredential::tokenFor(static::getContainer(), $this->owner, 'public mcp', $this->other);

        $this->send(Request::METHOD_POST, $this->runs().'/'.$run->id.'/stop', '{}', $bound);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->countCommands($this->em));
    }

    private function runIn(WorkerRunState $state, ?Project $project = null, string $receivedAt = '2026-01-01 10:00:00', int $cardNumber = 1): WorkerRun
    {
        return $this->seedRun($this->em, $project ?? $this->project, receivedAt: new \DateTimeImmutable($receivedAt), cardNumber: $cardNumber, exitCode: 1, bridgeId: $this->bridge->id, state: $state, runKey: Uuid::v7());
    }

    private function onlyCommand(): BridgeCommand
    {
        $repository = static::getContainer()->get(BridgeCommandRepository::class);
        self::assertInstanceOf(BridgeCommandRepository::class, $repository);
        $commands = $repository->findAll();
        self::assertCount(1, $commands);

        return $commands[0];
    }

    private function runs(): string
    {
        return '/api/v1/projects/'.$this->project->id.'/runs';
    }

    private function send(string $method, string $url, string $body = '', ?string $token = ''): void
    {
        $bearer = '' === $token ? $this->token : $token;
        $this->client->request($method, $url, server: ['CONTENT_TYPE' => 'application/json'] + (null === $bearer ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$bearer]), content: $body);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
