<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Module\Account\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Http\AccessMapInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * Guards the deny-by-default rule on ^/api in config/packages/security.yaml.
 *
 * The api firewall matches every /api path but only /api/v1/live-review-widget names a
 * scope, and every API-token user also carries ROLE_USER — so an /api endpoint
 * added without its own access_control rule would be authorized by the ^/
 * catch-all for any scoped token. These tests assert the decision itself rather
 * than the YAML, so they keep holding however the rule is expressed.
 */
final class ApiAccessControlTest extends KernelTestCase
{
    /**
     * A token carrying every role the app issues — the strongest caller that
     * could reach an unguarded endpoint.
     */
    private const array ALL_ROLES = ['ROLE_USER', 'ROLE_API_SITE_REVIEW', 'ROLE_API_AGENT', 'ROLE_API_MCP', 'ROLE_ADMIN'];

    public function test_an_api_path_with_no_scope_rule_is_denied_to_every_role(): void
    {
        self::assertFalse($this->decide('/api/some-future-endpoint', self::ALL_ROLES));
    }

    public function test_the_version_list_and_the_public_spec_need_no_role_and_nothing_else_under_v1_is_open(): void
    {
        self::assertTrue($this->decide('/api/versions', []));
        self::assertTrue($this->decide('/api/v1/openapi.json', []));
        self::assertFalse($this->decide('/api/v1/projects', self::ALL_ROLES));
        self::assertFalse($this->decide('/api/v1/openapi.json.bak', self::ALL_ROLES));
        self::assertFalse($this->decide('/api/versions/x', self::ALL_ROLES));
    }

    public function test_the_public_scope_reaches_the_project_routes_and_no_other_scope_does(): void
    {
        self::assertTrue($this->decide('/api/v1/projects', ['ROLE_USER', 'ROLE_API_PUBLIC']));
        self::assertTrue($this->decide('/api/v1/projects/my-project', ['ROLE_USER', 'ROLE_API_PUBLIC']));
        self::assertFalse($this->decide('/api/v1/projects', ['ROLE_USER', 'ROLE_API_AGENT', 'ROLE_API_MCP', 'ROLE_API_SITE_REVIEW']));
        self::assertFalse($this->decide('/api/v1/projects/my-project', ['ROLE_USER', 'ROLE_API_AGENT', 'ROLE_API_MCP', 'ROLE_API_SITE_REVIEW']));
    }

    /** A rule per route shape, so a route the contract adds later inherits no grant from the project rule. */
    public function test_the_project_rule_opens_no_nested_route(): void
    {
        self::assertFalse($this->decide('/api/v1/projects/my-project/not-a-route', ['ROLE_USER', 'ROLE_API_PUBLIC']));
        self::assertFalse($this->decide('/api/v1/projects/my-project/', ['ROLE_USER', 'ROLE_API_PUBLIC']));
        self::assertFalse($this->decide('/api/v1/projectsx', ['ROLE_USER', 'ROLE_API_PUBLIC']));
    }

    public function test_the_public_scope_reaches_the_card_routes_and_no_other_scope_does(): void
    {
        foreach (['/api/v1/projects/my-project/cards', '/api/v1/projects/my-project/cards/12', '/api/v1/projects/my-project/cards/12/events', '/api/v1/projects/my-project/cards/12/hold', '/api/v1/projects/my-project/cards/12/pause'] as $path) {
            self::assertTrue($this->decide($path, ['ROLE_USER', 'ROLE_API_PUBLIC']), $path);
            self::assertFalse($this->decide($path, ['ROLE_USER', 'ROLE_API_AGENT', 'ROLE_API_MCP', 'ROLE_API_SITE_REVIEW']), $path);
        }
    }

    public function test_the_card_rule_opens_no_other_card_route(): void
    {
        foreach (['/api/v1/projects/my-project/cards/', '/api/v1/projects/my-project/cards/0', '/api/v1/projects/my-project/cards/abc', '/api/v1/projects/my-project/cards/12/comments', '/api/v1/projects/my-project/cards/12/events/3', '/api/v1/projects/my-project/cards/1234567890', '/api/v1/projects/my-project/cardsx'] as $path) {
            self::assertFalse($this->decide($path, ['ROLE_USER', 'ROLE_API_PUBLIC']), $path);
        }
    }

    public function test_the_public_scope_reaches_the_discovery_route_and_no_other_scope_does(): void
    {
        self::assertTrue($this->decide('/api/v1/projects/my-project/discovery', ['ROLE_USER', 'ROLE_API_PUBLIC']));
        self::assertFalse($this->decide('/api/v1/projects/my-project/discovery', ['ROLE_USER', 'ROLE_API_AGENT', 'ROLE_API_MCP', 'ROLE_API_SITE_REVIEW']));
        self::assertFalse($this->decide('/api/v1/projects/my-project/discovery/', ['ROLE_USER', 'ROLE_API_PUBLIC']));
        self::assertFalse($this->decide('/api/v1/projects/my-project/discovery/1', ['ROLE_USER', 'ROLE_API_PUBLIC']));
    }

    public function test_the_public_scope_reaches_the_column_routes_and_no_other_scope_does(): void
    {
        foreach (['/api/v1/projects/my-project/board/columns', '/api/v1/projects/my-project/board/columns/order', '/api/v1/projects/my-project/board/columns/next'] as $path) {
            self::assertTrue($this->decide($path, ['ROLE_USER', 'ROLE_API_PUBLIC']), $path);
            self::assertFalse($this->decide($path, ['ROLE_USER', 'ROLE_API_AGENT', 'ROLE_API_MCP', 'ROLE_API_SITE_REVIEW']), $path);
        }
    }

    public function test_the_column_rule_opens_no_other_board_route(): void
    {
        self::assertFalse($this->decide('/api/v1/projects/my-project/board/columns/next/cards', ['ROLE_USER', 'ROLE_API_PUBLIC']));
        self::assertFalse($this->decide('/api/v1/projects/my-project/board/columns/', ['ROLE_USER', 'ROLE_API_PUBLIC']));
        self::assertFalse($this->decide('/api/v1/projects/my-project/board', ['ROLE_USER', 'ROLE_API_PUBLIC']));
        self::assertFalse($this->decide('/api/v1/projects/my-project/board/columnsx', ['ROLE_USER', 'ROLE_API_PUBLIC']));
    }

    public function test_the_public_scope_reaches_the_feedback_and_artifact_routes_and_no_other_scope_does(): void
    {
        $paths = [
            '/api/v1/projects/my-project/feedback',
            '/api/v1/projects/my-project/feedback/0198a3c4-0000-7000-8000-000000000001',
            '/api/v1/projects/my-project/artifacts',
            '/api/v1/projects/my-project/artifacts/0198a3c4-0000-7000-8000-000000000001',
            '/api/v1/projects/my-project/artifacts/0198a3c4-0000-7000-8000-000000000001/versions',
            '/api/v1/projects/my-project/artifacts/0198a3c4-0000-7000-8000-000000000001/files/index.html',
            '/api/v1/projects/my-project/artifacts/0198a3c4-0000-7000-8000-000000000001/files/css%2Fapp.css',
            '/api/v1/projects/my-project/artifacts/0198a3c4-0000-7000-8000-000000000001/files/css/app.css',
        ];
        foreach ($paths as $path) {
            self::assertTrue($this->decide($path, ['ROLE_USER', 'ROLE_API_PUBLIC']), $path);
            self::assertFalse($this->decide($path, ['ROLE_USER', 'ROLE_API_AGENT', 'ROLE_API_MCP', 'ROLE_API_SITE_REVIEW']), $path);
        }
    }

    public function test_the_public_scope_reaches_the_document_routes_and_no_other_scope_does(): void
    {
        $document = '0f6e6b9e-8f1c-4c4e-9a3a-1d2b3c4d5e6f';
        foreach ([
            '/api/v1/projects/my-project/documents',
            '/api/v1/projects/my-project/documents/'.$document,
            '/api/v1/projects/my-project/documents/'.$document.'/publish',
            '/api/v1/projects/my-project/documents/'.$document.'/versions',
            '/api/v1/projects/my-project/documents/'.$document.'/versions/2',
            '/api/v1/projects/my-project/documents/'.$document.'/review',
            '/api/v1/projects/my-project/documents/'.$document.'/comments/'.$document,
            '/api/v1/projects/my-project/documents/'.$document.'/comments/'.$document.'/replies',
            '/api/v1/projects/my-project/documents/'.$document.'/decisions/etag-scope',
            '/api/v1/projects/my-project/documents/'.$document.'/verdicts',
            '/api/v1/projects/my-project/documents/'.$document.'/verdicts/'.$document,
        ] as $path) {
            self::assertTrue($this->decide($path, ['ROLE_USER', 'ROLE_API_PUBLIC']), $path);
            self::assertFalse($this->decide($path, ['ROLE_USER', 'ROLE_API_AGENT', 'ROLE_API_MCP', 'ROLE_API_SITE_REVIEW']), $path);
        }
    }

    public function test_the_feedback_and_artifact_rules_open_no_other_route(): void
    {
        $paths = [
            '/api/v1/projects/my-project/feedback/',
            '/api/v1/projects/my-project/feedback/abc/extra',
            '/api/v1/projects/my-project/feedbackx',
            '/api/v1/projects/my-project/artifacts/',
            '/api/v1/projects/my-project/artifacts/abc/extra',
            '/api/v1/projects/my-project/artifacts/abc/versions/1',
            '/api/v1/projects/my-project/artifacts/abc/files',
            '/api/v1/projects/my-project/artifacts/abc/files/',
            '/api/v1/projects/my-project/artifactsx',
        ];
        foreach ($paths as $path) {
            self::assertFalse($this->decide($path, ['ROLE_USER', 'ROLE_API_PUBLIC']), $path);
        }
    }

    /** The document rule names its routes, so a later route under a document starts denied. */
    public function test_the_document_rule_opens_no_other_route(): void
    {
        $document = '0f6e6b9e-8f1c-4c4e-9a3a-1d2b3c4d5e6f';
        foreach ([
            '/api/v1/projects/my-project/documents/',
            '/api/v1/projects/my-project/documents/'.$document.'/',
            '/api/v1/projects/my-project/documents/'.$document.'/review/extra',
            '/api/v1/projects/my-project/documents/'.$document.'/comments',
            '/api/v1/projects/my-project/documents/'.$document.'/comments/'.$document.'/replies/extra',
            '/api/v1/projects/my-project/documents/'.$document.'/decisions',
            '/api/v1/projects/my-project/documents/'.$document.'/verdicts/'.$document.'/extra',
            '/api/v1/projects/my-project/documents/'.$document.'/versions/2/extra',
            '/api/v1/projects/my-project/documents/'.$document.'/publish/extra',
            '/api/v1/projects/my-project/documentsx',
        ] as $path) {
            self::assertFalse($this->decide($path, ['ROLE_USER', 'ROLE_API_PUBLIC']), $path);
        }
    }

    public function test_the_site_review_scope_reaches_the_private_widget_prefix_and_no_other_scope_does(): void
    {
        self::assertTrue($this->decide('/api/v1/live-review-widget/review', ['ROLE_USER', 'ROLE_API_SITE_REVIEW']));
        self::assertTrue($this->decide('/api/v1/live-review-widget/cards/abc/verdicts', ['ROLE_USER', 'ROLE_API_SITE_REVIEW']));
        self::assertFalse($this->decide('/api/v1/live-review-widget/review', ['ROLE_USER', 'ROLE_API_AGENT']));
        self::assertFalse($this->decide('/api/v1/live-review-widget/review', ['ROLE_USER', 'ROLE_API_MCP']));
        self::assertFalse($this->decide('/api/v1/live-review-widgets', ['ROLE_USER', 'ROLE_API_SITE_REVIEW']));
    }

    public function test_a_site_review_token_stays_denied_outside_its_own_prefix(): void
    {
        self::assertFalse($this->decide('/api/other', ['ROLE_USER', 'ROLE_API_SITE_REVIEW']));
    }

    #[DataProvider('bridgePaths')]
    public function test_a_bridge_path_admits_the_agent_scope_and_no_other(string $template): void
    {
        $sample = ['{bridgeId}' => 'b1', '{commandId}' => 'c1', '{workRequestId}' => 'w1', '{sessionId}' => 's1', '{handle}' => 'loupe', '{runId}' => 'r1', '{cardId}' => 'k1', '{askId}' => 'a1', '{experiment}' => 'e1', '{label}' => 'l1'];
        $path = strtr($template, $sample);
        self::assertTrue($this->decide($path, ['ROLE_USER', 'ROLE_API_AGENT']), $path);
        self::assertFalse($this->decide($path, ['ROLE_USER', 'ROLE_API_SITE_REVIEW', 'ROLE_API_MCP']), $path);
        self::assertFalse($this->decide($path.'/x/y', self::ALL_ROLES), $path);
    }

    /** @return array<string, array{string}> */
    public static function bridgePaths(): array
    {
        $paths = [
            '/api/v1/bridge/bridges/{bridgeId}/commands/{commandId}',
            '/api/v1/bridge/bridges/{bridgeId}/heartbeat',
            '/api/v1/bridge/bridges/{bridgeId}/runs',
            '/api/v1/bridge/bridges/{bridgeId}/work-requests/{workRequestId}/claim',
            '/api/v1/bridge/bridges/{bridgeId}/work-requests/{workRequestId}/result',
            '/api/v1/bridge/card-holds',
            '/api/v1/bridge/events',
            '/api/v1/bridge/events/replay',
            '/api/v1/bridge/projects',
            '/api/v1/bridge/projects/{handle}/cards/{cardId}',
            '/api/v1/bridge/projects/{handle}/columns',
            '/api/v1/bridge/interactive-sessions/{sessionId}/close',
            '/api/v1/bridge/interactive-sessions/{sessionId}/card-run',
            '/api/v1/bridge/projects/{handle}/worker-runs/{runId}',
            '/api/v1/bridge/projects/{handle}/worker-runs/sessions/{sessionId}/usage',
            '/api/v1/bridge/projects/{handle}/worker-runs/{runId}/tool-calls',
            '/api/v1/bridge/projects/{handle}/worker-runs/{runId}/output',
            '/api/v1/bridge/projects/{handle}/interactive-runs/{sessionId}',
            '/api/v1/bridge/projects/{handle}/experiments/{experiment}/pins/{cardId}',
            '/api/v1/bridge/projects/{handle}/inbox/asks/{askId}',
            '/api/v1/bridge/projects/{handle}/git-token',
            '/api/v1/bridge/projects/{handle}/preview-tokens',
            '/api/v1/bridge/projects/{handle}/preview-tokens/{label}',
            '/api/v1/bridge/projects/{handle}/artifacts',
        ];

        return array_combine($paths, array_map(static fn (string $path): array => [$path], $paths));
    }

    public function test_the_deny_rule_does_not_shadow_the_agent_scope(): void
    {
        self::assertTrue($this->decide('/api/v1/bridge/projects', ['ROLE_USER', 'ROLE_API_AGENT']));
        self::assertTrue($this->decide('/api/v1/bridge/events', ['ROLE_USER', 'ROLE_API_AGENT']));
        self::assertTrue($this->decide('/api/v1/bridge/projects/loupe/columns', ['ROLE_USER', 'ROLE_API_AGENT']));
        self::assertTrue($this->decide('/api/v1/bridge/projects/loupe/worker-runs/1', ['ROLE_USER', 'ROLE_API_AGENT']));
        self::assertTrue($this->decide('/api/v1/bridge/projects/loupe/inbox/asks/0f6e6b9e-8f1c-4c4e-9a3a-1d2b3c4d5e6f', ['ROLE_USER', 'ROLE_API_AGENT']));
        self::assertTrue($this->decide('/api/v1/bridge/bridges/0f6e6b9e-8f1c-4c4e-9a3a-1d2b3c4d5e6f/heartbeat', ['ROLE_USER', 'ROLE_API_AGENT']));
    }

    /** The ask check rule grants one route, so a path near it stays denied by default. */
    public function test_the_inbox_ask_check_rule_does_not_open_the_rest_of_a_project(): void
    {
        $ask = '0f6e6b9e-8f1c-4c4e-9a3a-1d2b3c4d5e6f';
        self::assertFalse($this->decide('/api/v1/bridge/projects/loupe/inbox', self::ALL_ROLES));
        self::assertFalse($this->decide('/api/v1/bridge/projects/loupe/inbox/asks', self::ALL_ROLES));
        self::assertFalse($this->decide('/api/v1/bridge/projects/loupe/inbox/asks/', self::ALL_ROLES));
        self::assertFalse($this->decide('/api/v1/bridge/projects/loupe/inbox/asks/'.$ask.'/items', self::ALL_ROLES));
        self::assertFalse($this->decide('/api/v1/bridge/projects/client/app/inbox/asks/'.$ask, self::ALL_ROLES));
        self::assertFalse($this->decide('/api/v1/bridge/projects/loupe/inbox/asks/'.$ask, ['ROLE_USER', 'ROLE_API_SITE_REVIEW', 'ROLE_API_MCP']));
    }

    /** The heartbeat rule grants one route, so nothing beside it under /api/v1/bridge/bridges opens. */
    public function test_the_heartbeat_rule_does_not_open_the_rest_of_a_bridge(): void
    {
        $bridge = '0f6e6b9e-8f1c-4c4e-9a3a-1d2b3c4d5e6f';
        self::assertFalse($this->decide('/api/v1/bridge/bridges', self::ALL_ROLES));
        self::assertFalse($this->decide('/api/v1/bridge/bridges/'.$bridge, self::ALL_ROLES));
        self::assertFalse($this->decide('/api/v1/bridge/bridges/'.$bridge.'/heartbeat/extra', self::ALL_ROLES));
        self::assertFalse($this->decide('/api/v1/bridge/bridges/a/b/heartbeat', self::ALL_ROLES));
        self::assertFalse($this->decide('/api/v1/bridge/bridges/'.$bridge.'/heartbeat', ['ROLE_USER', 'ROLE_API_SITE_REVIEW', 'ROLE_API_MCP']));
        self::assertFalse($this->decide('/api/v1/bridge/bridges/'.$bridge.'/runs/extra', self::ALL_ROLES));
        self::assertFalse($this->decide('/api/v1/bridge/bridges/'.$bridge.'/runs', ['ROLE_USER', 'ROLE_API_SITE_REVIEW', 'ROLE_API_MCP']));
        self::assertTrue($this->decide('/api/v1/bridge/bridges/'.$bridge.'/runs', ['ROLE_USER', 'ROLE_API_AGENT']));
    }

    /** The ack rule grants one command of one bridge, and to an agent token alone. */
    public function test_the_command_ack_rule_does_not_open_the_rest_of_a_bridge(): void
    {
        $bridge = '0f6e6b9e-8f1c-4c4e-9a3a-1d2b3c4d5e6f';
        $command = '1a2b3c4d-8f1c-4c4e-9a3a-1d2b3c4d5e6f';
        self::assertTrue($this->decide('/api/v1/bridge/bridges/'.$bridge.'/commands/'.$command, ['ROLE_USER', 'ROLE_API_AGENT']));
        self::assertFalse($this->decide('/api/v1/bridge/bridges/'.$bridge.'/commands/'.$command, ['ROLE_USER', 'ROLE_API_SITE_REVIEW', 'ROLE_API_MCP']));
        self::assertFalse($this->decide('/api/v1/bridge/bridges/'.$bridge.'/commands', self::ALL_ROLES));
        self::assertFalse($this->decide('/api/v1/bridge/bridges/'.$bridge.'/commands/', self::ALL_ROLES));
        self::assertFalse($this->decide('/api/v1/bridge/bridges/'.$bridge.'/commands/'.$command.'/extra', self::ALL_ROLES));
        self::assertFalse($this->decide('/api/v1/bridge/bridges/a/b/commands/'.$command, self::ALL_ROLES));
    }

    /** The work request rules grant the claim and the result of one request, and to an agent token alone. */
    public function test_the_work_request_rules_do_not_open_the_rest_of_a_bridge(): void
    {
        $prefix = '/api/v1/bridge/bridges/0f6e6b9e-8f1c-4c4e-9a3a-1d2b3c4d5e6f/work-requests/1a2b3c4d-8f1c-4c4e-9a3a-1d2b3c4d5e6f';
        foreach (['claim', 'result'] as $action) {
            self::assertTrue($this->decide($prefix.'/'.$action, ['ROLE_USER', 'ROLE_API_AGENT']));
            self::assertFalse($this->decide($prefix.'/'.$action, ['ROLE_USER', 'ROLE_API_SITE_REVIEW', 'ROLE_API_MCP']));
            self::assertFalse($this->decide($prefix.'/'.$action.'/extra', self::ALL_ROLES));
        }
        self::assertFalse($this->decide($prefix, self::ALL_ROLES));
        self::assertFalse($this->decide($prefix.'/withdraw', self::ALL_ROLES));
        self::assertFalse($this->decide('/api/v1/bridge/bridges/a/b/work-requests/c/claim', self::ALL_ROLES));
    }

    /** The bridge rule report route is gone, so an agent token no longer opens it. */
    public function test_no_bridge_rules_route_stays_open(): void
    {
        $bridge = '0f6e6b9e-8f1c-4c4e-9a3a-1d2b3c4d5e6f';
        self::assertFalse($this->decide('/api/v1/bridge/projects/loupe/bridges/'.$bridge, self::ALL_ROLES));
        self::assertFalse($this->decide('/api/v1/bridge/projects/loupe/bridges/'.$bridge.'/rules', self::ALL_ROLES));
    }

    /** The run rules grant one route each, so a path near them stays denied by default. */
    public function test_the_worker_run_rule_does_not_open_the_rest_of_a_project(): void
    {
        self::assertFalse($this->decide('/api/v1/bridge/projects/loupe/worker-runs/1/extra', self::ALL_ROLES));
        self::assertFalse($this->decide('/api/v1/bridge/projects/loupe/worker-runs/', self::ALL_ROLES));
        self::assertFalse($this->decide('/api/v1/bridge/projects/client/app/worker-runs/1', self::ALL_ROLES));
        self::assertFalse($this->decide('/api/v1/bridge/projects/loupe/worker-runs/1', ['ROLE_USER', 'ROLE_API_SITE_REVIEW', 'ROLE_API_MCP']));
        self::assertTrue($this->decide('/api/v1/bridge/projects/loupe/worker-runs/1', ['ROLE_USER', 'ROLE_API_AGENT']));
        self::assertFalse($this->decide('/api/v1/bridge/projects/loupe/worker-runs', self::ALL_ROLES));
    }

    public function test_the_tool_call_rule_grants_the_agent_scope_one_route(): void
    {
        self::assertTrue($this->decide('/api/v1/bridge/projects/loupe/worker-runs/1/tool-calls', ['ROLE_USER', 'ROLE_API_AGENT']));
        self::assertFalse($this->decide('/api/v1/bridge/projects/loupe/worker-runs/1/tool-calls', ['ROLE_USER', 'ROLE_API_SITE_REVIEW', 'ROLE_API_MCP']));
        self::assertFalse($this->decide('/api/v1/bridge/projects/loupe/worker-runs/1/tool-calls/2', self::ALL_ROLES));
        self::assertFalse($this->decide('/api/v1/bridge/projects/loupe/worker-runs/1/2/tool-calls', self::ALL_ROLES));
    }

    /**
     * The agent grant names each route, so a new route under
     * /api/v1/bridge/projects starts denied rather than inheriting the agent scope.
     */
    public function test_the_agent_grant_covers_its_routes_and_nothing_near_them(): void
    {
        $agent = ['ROLE_USER', 'ROLE_API_AGENT'];
        self::assertFalse($this->decide('/api/v1/bridge/projects/', $agent));
        self::assertFalse($this->decide('/api/v1/bridge/projects/my-app', $agent));
        self::assertFalse($this->decide('/api/v1/bridge/projects/my-app/stream', $agent));
        self::assertFalse($this->decide('/api/v1/bridge/events/', $agent));
        self::assertFalse($this->decide('/api/v1/bridge/events/more', $agent));
        self::assertFalse($this->decide('/api/v1/bridge/eventsx', $agent));
        self::assertFalse($this->decide('/api/v1/bridge/projects/my-app/comments', $agent));
        self::assertFalse($this->decide('/api/agent/sites', $agent));
        self::assertFalse($this->decide('/api/agent/stream', $agent));
    }

    /**
     * The two scopes are disjoint in both directions. This is the whole point of
     * splitting them: a widget token is embedded in public page HTML, and an
     * agent token enumerates every project the owner has.
     */
    public function test_the_agent_scope_and_the_site_review_scope_do_not_reach_each_other(): void
    {
        self::assertFalse($this->decide('/api/v1/live-review-widget/review', ['ROLE_USER', 'ROLE_API_AGENT']));
        self::assertFalse($this->decide('/api/v1/live-review-widget/cards', ['ROLE_USER', 'ROLE_API_AGENT']));
        self::assertFalse($this->decide('/api/v1/bridge/projects', ['ROLE_USER', 'ROLE_API_SITE_REVIEW']));
        self::assertFalse($this->decide('/api/v1/bridge/events', ['ROLE_USER', 'ROLE_API_SITE_REVIEW']));
        self::assertFalse($this->decide('/api/v1/bridge/projects/loupe/columns', ['ROLE_USER', 'ROLE_API_SITE_REVIEW']));
        self::assertFalse($this->decide('/api/v1/bridge/projects/loupe/cards/0f6e6b9e-8f1c-4c4e-9a3a-1d2b3c4d5e6f', ['ROLE_USER', 'ROLE_API_SITE_REVIEW', 'ROLE_API_MCP']));
    }

    /** The card rule grants one route, so a path near it stays denied by default. */
    public function test_the_card_rule_does_not_open_the_rest_of_a_board(): void
    {
        $card = '0f6e6b9e-8f1c-4c4e-9a3a-1d2b3c4d5e6f';
        self::assertTrue($this->decide('/api/v1/bridge/projects/loupe/cards/'.$card, ['ROLE_USER', 'ROLE_API_AGENT']));
        self::assertFalse($this->decide('/api/v1/bridge/projects/loupe/cards/', self::ALL_ROLES));
        self::assertFalse($this->decide('/api/v1/bridge/projects/loupe/cards/'.$card.'/move', self::ALL_ROLES));
        self::assertFalse($this->decide('/api/v1/bridge/projects/client/app/cards/'.$card, self::ALL_ROLES));
    }

    /** The column rule grants one route, so another path under a project stays denied by default. */
    public function test_the_column_rule_does_not_open_the_rest_of_a_project(): void
    {
        self::assertFalse($this->decide('/api/v1/bridge/projects/loupe/cards', self::ALL_ROLES));
        self::assertFalse($this->decide('/api/v1/bridge/projects/loupe/columns/extra', self::ALL_ROLES));
        self::assertFalse($this->decide('/api/v1/bridge/projects/client/app/columns', self::ALL_ROLES));
        self::assertFalse($this->decide('/api/v1/bridge/projects/client%2Fapp/columns', self::ALL_ROLES));
    }

    /**
     * Runs a path through the configured access_control map and returns the
     * authorization decision for a caller holding exactly $roles.
     *
     * @param list<string> $roles
     */
    private function decide(string $path, array $roles): bool
    {
        // security.access_map has no interface alias in the container, so it is
        // fetched by service id; the decision manager does, and is fetched by
        // interface for the type PHPStan needs.
        $container = static::getContainer();
        $accessMap = $container->get('security.access_map');
        self::assertInstanceOf(AccessMapInterface::class, $accessMap);
        $decisionManager = $container->get(AccessDecisionManagerInterface::class);

        $request = Request::create('https://loupe.test'.$path);
        [$attributes] = $accessMap->getPatterns($request);
        self::assertNotNull($attributes, sprintf('No access_control rule matches %s.', $path));

        $user = new User(fullName: 'Caller', email: 'caller@example.com', password: 'x');

        return $decisionManager->decide(
            new PostAuthenticationToken($user, 'api', $roles),
            $attributes,
            $request,
        );
    }
}
