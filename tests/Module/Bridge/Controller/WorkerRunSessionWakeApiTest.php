<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunWakeReport;
use App\Module\Bridge\ValueObject\WorkerRunWakeTrigger;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\AgentCredential;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

final class WorkerRunSessionWakeApiTest extends WebTestCase
{
    use BridgeScenario;

    public function test_each_wake_fills_the_run_it_matches_in_start_order_and_a_null_leaves_a_run_alone(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'session-wake-order@example.com');
        $project = $this->project($em, $owner, 'Session Wake Order');
        $session = Uuid::v4();
        $third = $this->sessionRun($project, $session, '2026-09-23 12:00:00');
        $first = $this->sessionRun($project, $session, '2026-09-23 10:00:00');
        $second = $this->sessionRun($project, $session, '2026-09-23 11:00:00');
        $this->sessionRun($project, $session, '2026-09-23 09:00:00', WorkerRunKind::Interactive);
        $this->sessionRun($project, $session, null);
        $this->sessionRun($this->project($em, $owner, 'Session Wake Elsewhere'), $session, '2026-09-23 09:00:00');

        $this->put($client, $project, (string) $session, $this->agentToken($client, $owner), ['wakes' => [
            null,
            ['trigger' => 'backfill', 'lastTurnAt' => '2026-09-23T10:30:00Z', 'firstTurn' => ['inputTokens' => 7, 'cacheReadTokens' => 300, 'cacheWriteTokens' => 900]],
            ['trigger' => 'person'],
        ]]);

        self::assertResponseStatusCodeSame(204);
        self::assertNull($this->runOf($first)->wakeTrigger);
        $stored = $this->runOf($second);
        self::assertSame([WorkerRunWakeTrigger::Backfill, 1_800_000, 7, 300, 900], [$stored->wakeTrigger, $stored->wakeGapMs, $stored->wakeFirstInputTokens, $stored->wakeFirstCacheReadTokens, $stored->wakeFirstCacheWriteTokens]);
        self::assertSame(['backfill', 1_800_000, 900], $this->factOf($second));
        self::assertSame([WorkerRunWakeTrigger::Person, null], [$this->runOf($third)->wakeTrigger, $this->runOf($third)->wakeGapMs]);
        self::assertSame([null, null, null], $this->factOf($first));
    }

    public function test_a_run_that_holds_a_wake_keeps_it_and_the_answer_is_the_same(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'session-wake-held@example.com');
        $project = $this->project($em, $owner, 'Session Wake Held');
        $session = Uuid::v4();
        $held = $this->sessionRun($project, $session, '2026-09-23 10:00:00');
        $held->recordWake(new WorkerRunWakeReport(WorkerRunWakeTrigger::AskClosed, null, 1, 2, 3));
        $em->flush();
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $project, (string) $session, $raw, ['wakes' => [['trigger' => 'backfill']]]);
        $this->put($client, $project, (string) $session, $raw, ['wakes' => [['trigger' => 'backfill']]]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame([WorkerRunWakeTrigger::AskClosed, 3], [$this->runOf($held)->wakeTrigger, $this->runOf($held)->wakeFirstCacheWriteTokens]);
        self::assertSame(['ask-closed', null, 3], $this->factOf($held));
    }

    public function test_a_second_report_fills_nothing_new(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'session-wake-twice@example.com');
        $project = $this->project($em, $owner, 'Session Wake Twice');
        $session = Uuid::v4();
        $run = $this->sessionRun($project, $session, '2026-09-23 10:00:00');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $project, (string) $session, $raw, ['wakes' => [['trigger' => 'backfill', 'lastTurnAt' => '2026-09-23T09:00:00Z']]]);
        $this->put($client, $project, (string) $session, $raw, ['wakes' => [['trigger' => 'person', 'lastTurnAt' => '2026-09-23T09:50:00Z']]]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame([WorkerRunWakeTrigger::Backfill, 3_600_000], [$this->runOf($run)->wakeTrigger, $this->runOf($run)->wakeGapMs]);
    }

    public function test_a_count_that_differs_from_the_runs_writes_nothing(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'session-wake-count@example.com');
        $project = $this->project($em, $owner, 'Session Wake Count');
        $session = Uuid::v4();
        $run = $this->sessionRun($project, $session, '2026-09-23 10:00:00');

        $this->put($client, $project, (string) $session, $this->agentToken($client, $owner), ['wakes' => [['trigger' => 'person'], ['trigger' => 'person']]]);

        self::assertResponseStatusCodeSame(409);
        $this->assertProblemCode($client, 'process_count_mismatch');
        self::assertNull($this->runOf($run)->wakeTrigger);
    }

    public function test_two_runs_that_start_in_the_same_second_write_nothing(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'session-wake-same@example.com');
        $project = $this->project($em, $owner, 'Session Wake Same');
        $session = Uuid::v4();
        $first = $this->sessionRun($project, $session, '2026-09-23 10:00:00');
        $this->sessionRun($project, $session, '2026-09-23 10:00:00');

        $this->put($client, $project, (string) $session, $this->agentToken($client, $owner), ['wakes' => [['trigger' => 'person'], ['trigger' => 'person']]]);

        self::assertResponseStatusCodeSame(409);
        $this->assertProblemCode($client, 'ambiguous_start_order');
        self::assertNull($this->runOf($first)->wakeTrigger);
    }

    public function test_a_last_turn_after_the_start_of_its_run_writes_nothing(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'session-wake-late@example.com');
        $project = $this->project($em, $owner, 'Session Wake Late');
        $session = Uuid::v4();
        $first = $this->sessionRun($project, $session, '2026-09-23 10:00:00');
        $second = $this->sessionRun($project, $session, '2026-09-23 11:00:00');

        $this->put($client, $project, (string) $session, $this->agentToken($client, $owner), ['wakes' => [['trigger' => 'person'], ['trigger' => 'person', 'lastTurnAt' => '2026-09-23T11:00:01Z']]]);

        self::assertResponseStatusCodeSame(422);
        $this->assertProblemCode($client, 'validation_failed');
        self::assertNull($this->runOf($first)->wakeTrigger);
        self::assertNull($this->runOf($second)->wakeTrigger);
    }

    public function test_another_users_project_answers_project_not_found_and_changes_no_run(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $caller = $this->user($em, 'session-wake-caller@example.com');
        $other = $this->project($em, $this->user($em, 'session-wake-other@example.com'), 'Session Wake Private');
        $session = Uuid::v4();
        $run = $this->sessionRun($other, $session, '2026-09-23 10:00:00');

        $this->put($client, $other, (string) $session, $this->agentToken($client, $caller), ['wakes' => [['trigger' => 'person']]]);

        self::assertResponseStatusCodeSame(404);
        $this->assertProblemCode($client, 'project_not_found');
        self::assertNull($this->runOf($run)->wakeTrigger);
    }

    public function test_a_session_id_that_is_not_a_uuid_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'session-wake-bad-id@example.com');
        $project = $this->project($em, $owner, 'Session Wake Bad Id');

        $this->put($client, $project, 'not-a-uuid', $this->agentToken($client, $owner), ['wakes' => [['trigger' => 'person']]]);

        self::assertResponseStatusCodeSame(422);
        $this->assertProblemCode($client, 'invalid_session_id');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidBodies(): iterable
    {
        yield 'no wakes' => [[]];
        yield 'an empty list' => [['wakes' => []]];
        yield 'too many wakes' => [['wakes' => array_fill(0, 101, null)]];
        yield 'no trigger' => [['wakes' => [['lastTurnAt' => '2026-09-23T09:00:00Z']]]];
        yield 'an unknown trigger' => [['wakes' => [['trigger' => 'whim']]]];
        yield 'a negative count' => [['wakes' => [['trigger' => 'person', 'firstTurn' => ['inputTokens' => -1, 'cacheReadTokens' => 0, 'cacheWriteTokens' => 0]]]]];
        yield 'a missing count' => [['wakes' => [['trigger' => 'person', 'firstTurn' => ['inputTokens' => 1, 'cacheReadTokens' => 0]]]]];
        yield 'a time that is not a time' => [['wakes' => [['trigger' => 'person', 'lastTurnAt' => 'yesterday']]]];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidBodies')]
    public function test_an_invalid_body_is_refused_and_writes_nothing(array $body): void
    {
        $client = static::createClient();
        $em = $this->em();
        $suffix = md5(serialize($body));
        $owner = $this->user($em, 'session-wake-invalid-'.$suffix.'@example.com');
        $project = $this->project($em, $owner, 'Session Wake Invalid '.substr($suffix, 0, 6));
        $session = Uuid::v4();
        $run = $this->sessionRun($project, $session, '2026-09-23 10:00:00');

        $this->put($client, $project, (string) $session, $this->agentToken($client, $owner), $body);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->runOf($run)->wakeTrigger);
    }

    public function test_a_widget_token_is_refused_by_the_firewall(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'session-wake-widget@example.com');
        $project = $this->project($em, $owner, 'Session Wake Widget');
        $session = Uuid::v4();
        $run = $this->sessionRun($project, $session, '2026-09-23 10:00:00');

        $this->put($client, $project, (string) $session, AgentCredential::tokenFor(static::getContainer(), $owner, 'site-review', $project), ['wakes' => [['trigger' => 'person']]]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->runOf($run)->wakeTrigger);
    }

    public function test_wake_reports_share_the_run_report_limit(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('limiter.agent_worker_runs', new RateLimiterFactory(
            ['id' => 'agent_worker_runs', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        $em = $this->em();
        $owner = $this->user($em, 'session-wake-limit@example.com');
        $project = $this->project($em, $owner, 'Session Wake Limit');
        $session = Uuid::v4();
        $this->sessionRun($project, $session, '2026-09-23 10:00:00');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $project, (string) $session, $raw, ['wakes' => [null]]);
        self::assertResponseStatusCodeSame(204);

        $this->put($client, $project, (string) $session, $raw, ['wakes' => [null]]);
        self::assertResponseStatusCodeSame(429);
    }

    private function sessionRun(Project $project, Uuid $session, ?string $startedAt, WorkerRunKind $kind = WorkerRunKind::Worker): WorkerRun
    {
        $em = $this->em();
        $run = $this->seedRun($em, $project, kind: $kind);
        $run->sessionId = $session;
        $run->startedAt = null === $startedAt ? null : new \DateTimeImmutable($startedAt);
        $em->flush();

        return $run;
    }

    /** @param array<string, mixed> $body */
    private function put(KernelBrowser $client, Project $project, string $sessionId, string $raw, array $body): void
    {
        $client->request(
            Request::METHOD_PUT,
            '/api/v1/bridge/projects/'.$project->id.'/worker-runs/sessions/'.$sessionId.'/wake',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    /** @return array{string|null, int|null, int|null} the trigger, the gap and the cache write of the fact row */
    private function factOf(WorkerRun $run): array
    {
        $row = $this->em()->getConnection()->fetchAssociative(
            'SELECT wake_trigger, wake_gap_ms, wake_first_cache_write_tokens FROM bridge_worker_run_facts WHERE run_id = ?',
            [(string) $run->id],
        );
        self::assertIsArray($row);

        return [$row['wake_trigger'], null === $row['wake_gap_ms'] ? null : (int) $row['wake_gap_ms'], null === $row['wake_first_cache_write_tokens'] ? null : (int) $row['wake_first_cache_write_tokens']];
    }

    private function runOf(WorkerRun $run): WorkerRun
    {
        $this->em()->clear();

        return $this->em()->find(WorkerRun::class, $run->id) ?? throw new \LogicException('The run is gone.');
    }

    private function assertProblemCode(KernelBrowser $client, string $code): void
    {
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame($code, $body['code'] ?? null);
    }
}
