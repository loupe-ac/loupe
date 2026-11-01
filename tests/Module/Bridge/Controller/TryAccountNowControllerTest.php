<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeWorkMatch;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** A person who may manage the project starts the waiting work of an account before its reset. */
final class TryAccountNowControllerTest extends WebTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-10-16 12:00:00';

    public function test_the_owner_presses_try_now_and_loupe_stores_the_override_the_command_and_the_offers_of_the_matched_work(): void
    {
        $client = static::createClient();
        static::getContainer()->set('clock', new MockClock(self::NOW));
        [$owner, $project, $bridge] = $this->scenario('press');
        $matched = $this->waiting($project, $bridge, 'claude');
        $this->waiting($project, $bridge, 'codex');
        $elsewhere = $this->waiting($project, $bridge, 'claude');
        $elsewhere->targetBridgeId = Uuid::v4();
        $this->em()->flush();
        $bridgeId = $bridge->id;
        $matchedId = $matched->id;
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $this->url($project, $bridgeId, 'claude'));

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/projects/'.$project->id.'/agents#agent-connection-'.$bridgeId);

        $overrides = $this->em()->getConnection()->fetchAllAssociative('SELECT owner_id, bridge_id, account, pressed_at, closed_at FROM bridge_account_try_now');
        self::assertSame([[
            'owner_id' => (string) $owner->id,
            'bridge_id' => (string) $bridgeId,
            'account' => 'claude',
            'pressed_at' => '2026-10-16 12:00:00',
            'closed_at' => null,
        ]], $overrides);

        $commands = $this->em()->getConnection()->fetchAllAssociative('SELECT id, kind, state, account, worker_run_id, requested_by_id, expires_at FROM bridge_commands');
        self::assertCount(1, $commands);
        self::assertSame('try-now', $commands[0]['kind']);
        self::assertSame('pending', $commands[0]['state']);
        self::assertSame('claude', $commands[0]['account']);
        self::assertNull($commands[0]['worker_run_id']);
        self::assertSame((string) $owner->id, $commands[0]['requested_by_id']);
        self::assertSame('2026-10-16 12:15:00', $commands[0]['expires_at']);

        self::assertSame([[
            'type' => 'bridge.command',
            'projectId' => (string) $project->id,
            'subject' => ['type' => 'bridge-command', 'id' => $commands[0]['id']],
            'commandId' => $commands[0]['id'],
            'kind' => 'try-now',
            'bridgeId' => (string) $bridgeId,
            'runId' => null,
            'runKey' => null,
            'sessionId' => null,
            'subjectType' => null,
            'subjectId' => null,
            'cardNumber' => null,
            'workRequestId' => null,
            'workKind' => null,
            'ruleId' => null,
            'startedAt' => null,
            'endedAt' => null,
            'expiresAt' => '2026-10-16T12:15:00+00:00',
            'cause' => 'person',
            'context' => new WorkRequestContext()->toArray(),
            'model' => null,
            'effort' => null,
            'harness' => null,
            'account' => 'claude',
            'runModel' => null,
            'inboxActionId' => null,
            'inboxItemNumber' => null,
            'inboxItemId' => null,
        ]], $this->payloads('bridge.command'));

        // Only the open work that the bridge matched to this account and may take is offered.
        $offers = $this->payloads('bridge.work_request');
        self::assertCount(1, $offers);
        self::assertSame((string) $matchedId, $offers[0]['workRequestId']);
        self::assertSame('open', $offers[0]['state']);
    }

    public function test_a_second_press_while_the_override_holds_changes_nothing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('clock', new MockClock(self::NOW));
        [$owner, $project, $bridge] = $this->scenario('twice');
        $this->waiting($project, $bridge, 'claude');
        $bridgeId = $bridge->id;
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $this->url($project, $bridgeId, 'claude'));
        $this->post($client, $this->url($project, $bridgeId, 'claude'));

        self::assertResponseStatusCodeSame(303);
        self::assertSame(1, $this->rowsIn('bridge_account_try_now'));
        self::assertSame(1, $this->rowsIn('bridge_commands'));
        self::assertCount(1, $this->payloads('bridge.command'));
        self::assertCount(1, $this->payloads('bridge.work_request'));
    }

    public function test_a_press_after_the_first_override_ran_out_opens_a_new_one(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $clock = new MockClock(self::NOW);
        static::getContainer()->set('clock', $clock);
        [$owner, $project, $bridge] = $this->scenario('again');
        $bridgeId = $bridge->id;
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $this->url($project, $bridgeId, 'claude'));
        $clock->sleep(15 * 60);
        $this->post($client, $this->url($project, $bridgeId, 'claude'));

        self::assertSame(
            [['2026-10-16 12:00:00', '2026-10-16 12:15:00'], ['2026-10-16 12:15:00', null]],
            $this->em()->getConnection()->fetchAllNumeric('SELECT pressed_at, closed_at FROM bridge_account_try_now ORDER BY pressed_at'),
        );
        self::assertSame(2, $this->rowsIn('bridge_commands'));
    }

    public function test_a_member_who_cannot_manage_the_project_is_refused_and_nothing_is_stored(): void
    {
        $client = static::createClient();
        $this->denyManage();
        [$owner, $project, $bridge] = $this->scenario('member');
        $bridgeId = $bridge->id;
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $this->url($project, $bridgeId, 'claude'));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->rowsIn('bridge_account_try_now'));
        self::assertSame(0, $this->rowsIn('bridge_commands'));
        self::assertSame([], $this->payloads('bridge.command'));
    }

    public function test_a_stranger_is_refused(): void
    {
        $client = static::createClient();
        [, $project, $bridge] = $this->scenario('outsider');
        $stranger = $this->user($this->em(), 'try-now-stranger@example.com');
        $bridgeId = $bridge->id;
        $this->em()->clear();

        $client->loginUser($stranger);
        $this->post($client, $this->url($project, $bridgeId, 'claude'));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->rowsIn('bridge_account_try_now'));
    }

    public function test_a_request_without_a_valid_csrf_token_is_refused(): void
    {
        $client = static::createClient();
        [$owner, $project, $bridge] = $this->scenario('csrf');
        $bridgeId = $bridge->id;
        $this->em()->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_POST, $this->url($project, $bridgeId, 'claude'), ['_csrf_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->rowsIn('bridge_account_try_now'));
    }

    public function test_a_bridge_that_does_not_follow_the_project_is_not_found(): void
    {
        $client = static::createClient();
        [$owner, , $bridge] = $this->scenario('elsewhere');
        $other = $this->project($this->em(), $owner, 'Try now other');
        $bridgeId = $bridge->id;
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $this->url($other, $bridgeId, 'claude'));

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->rowsIn('bridge_account_try_now'));
    }

    public function test_an_account_the_bridge_does_not_run_stores_nothing(): void
    {
        $client = static::createClient();
        [$owner, $project, $bridge] = $this->scenario('unknown');
        $bridgeId = $bridge->id;
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $this->url($project, $bridgeId, 'ghost'));

        self::assertResponseRedirects('/projects/'.$project->id.'/agents#agent-connection-'.$bridgeId);
        self::assertSame(0, $this->rowsIn('bridge_account_try_now'));
        self::assertSame(0, $this->rowsIn('bridge_commands'));
    }

    public function test_a_bridge_that_does_not_report_the_try_now_capability_stores_nothing(): void
    {
        $client = static::createClient();
        [$owner, $project, $bridge] = $this->scenario('outdated');
        $bridge->capabilities = [Bridge::CAPABILITY_COMMANDS, Bridge::CAPABILITY_WORK_REQUESTS];
        $this->em()->flush();
        $bridgeId = $bridge->id;
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $this->url($project, $bridgeId, 'claude'));

        self::assertResponseRedirects('/projects/'.$project->id.'/agents#agent-connection-'.$bridgeId);
        self::assertSame(0, $this->rowsIn('bridge_account_try_now'));
        self::assertSame(0, $this->rowsIn('bridge_commands'));
    }

    /** @return array{0: User, 1: Project, 2: Bridge} */
    private function scenario(string $name): array
    {
        $em = $this->em();
        $owner = $this->user($em, 'try-now-'.$name.'@example.com');
        $project = $this->project($em, $owner, 'Try now '.$name);
        $bridge = $this->seedBridge($em, $owner, projects: [(string) $project->id], accounts: [
            ['name' => 'claude', 'harness' => 'claude', 'state' => 'ready', 'reason' => null, 'kinds' => ['implement']],
            ['name' => 'codex', 'harness' => 'codex', 'state' => 'ready', 'reason' => null, 'kinds' => ['review']],
        ]);
        $bridge->capabilities = [Bridge::CAPABILITY_COMMANDS, Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_TRY_NOW];
        $em->flush();

        return [$owner, $project, $bridge];
    }

    private function waiting(Project $project, Bridge $bridge, string $account): WorkRequest
    {
        $request = $this->seedWorkRequest($this->em(), $project);
        $this->em()->persist(new BridgeWorkMatch(Uuid::v7(), $bridge, $request, $account));
        $this->em()->flush();

        return $request;
    }

    private function url(Project $project, Uuid $bridgeId, string $account): string
    {
        return '/projects/'.$project->id.'/agents/'.$bridgeId.'/accounts/'.$account.'/try-now';
    }

    /** The same-origin sentinel passes the CSRF check, so a refusal is the voter's or the lookup's. */
    private function post(KernelBrowser $client, string $url): void
    {
        $client->request(Request::METHOD_POST, $url, ['_csrf_token' => 'csrf-token'], [], ['HTTP_REFERER' => 'http://localhost'.$url]);
    }

    private function rowsIn(string $table): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    /** @return list<array<string, mixed>> */
    private function payloads(string $type): array
    {
        /** @var list<string> $payloads */
        $payloads = $this->em()->getConnection()->fetchFirstColumn('SELECT payload FROM outbox_events WHERE type = :type ORDER BY sequence', ['type' => $type]);

        return array_map(static fn (string $payload): array => json_decode($payload, true, flags: \JSON_THROW_ON_ERROR), $payloads);
    }
}
