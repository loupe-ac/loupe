<?php

declare(strict_types=1);

namespace App\Tests\Module\Mcp;

use App\Mcp\FlagGatedListToolsHandler;
use App\Module\OAuth\Repository\GrantRepository;
use App\Module\Review\Mcp\DocumentHighlightTool;
use App\Tests\Support\OAuthScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Registry;
use Mcp\Schema\Tool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class McpEndpointAuthTest extends WebTestCase
{
    private const string INIT = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}';

    public function test_missing_token_is_rejected(): void
    {
        $client = static::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: self::INIT);
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function test_invalid_token_is_rejected(): void
    {
        $client = static::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer invalid-token-value',
        ], content: self::INIT);
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function test_revoked_token_is_rejected(): void
    {
        $client = static::createClient();
        $scenario = new OAuthScenario(static::getContainer());
        $scenario->createClient();
        $user = $scenario->createUser('agent-revoked@example.com');
        $project = $scenario->createProject($user, 'Revoked Agent');
        $raw = $scenario->accessTokenFor($client, $user, 'mcp', $project);
        $grants = static::getContainer()->get(GrantRepository::class);
        self::assertInstanceOf(GrantRepository::class, $grants);
        self::assertInstanceOf(Uuid::class, $user->id);
        self::assertSame(1, $grants->revokeForUserAndClient($user->id, OAuthScenario::CLIENT_ID));

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
        ], content: self::INIT);
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function test_valid_token_authenticates(): void
    {
        $client = static::createClient();
        $raw = $this->persistValidToken($client);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
        ], content: self::INIT);
        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    public function test_request_on_the_app_host_is_not_blocked_by_dns_rebinding(): void
    {
        $client = static::createClient();
        $raw = $this->persistValidToken($client);

        // The MCP SDK's DNS-rebinding protection allows only localhost by default;
        // MCP_ALLOWED_HOSTS adds the app host so real clients can connect.
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/mcp', server: [
            'HTTP_HOST' => 'loupe.dev.localhost',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
        ], content: self::INIT);
        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    public function test_tools_list_returns_every_registered_tool(): void
    {
        $client = static::createClient();
        $raw = $this->persistValidToken($client);

        $listed = $this->listToolNames($client, $raw);

        self::assertSame($listed, array_values(array_unique($listed)), 'The list repeats a tool.');
        self::assertSame(self::registeredToolsWithoutGatedOnes(), $listed);
        foreach (['agent_review_submit', 'card_create', 'card_get', 'document_create', 'project_current'] as $mustKeep) {
            self::assertContains($mustKeep, $listed);
        }
    }

    public function test_tools_list_advertises_document_highlight_once_the_flag_is_on(): void
    {
        $client = static::createClient();
        $raw = $this->persistValidToken($client);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        static::getContainer()->get(FeatureFlagRepository::class)
            ->findAllIndexed()[DocumentHighlightTool::FLAG]->value = true;
        $em->flush();

        self::assertContains('document_highlight', $this->listToolNames($client, $raw));
    }

    public function test_one_tools_list_page_carries_every_tool(): void
    {
        $client = static::createClient();
        $raw = $this->persistValidToken($client);

        $pages = $this->listToolPages($client, $raw);

        // A client that stops after one page must still see every tool.
        self::assertCount(1, $pages);
        self::assertContains('project_current', $pages[0]);
    }

    /** @return list<string> */
    private function listToolNames(KernelBrowser $client, string $rawToken): array
    {
        $names = array_merge(...$this->listToolPages($client, $rawToken));
        sort($names);

        return $names;
    }

    /**
     * Follows nextCursor to the last page.
     *
     * @return list<list<string>>
     */
    private function listToolPages(KernelBrowser $client, string $rawToken): array
    {
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$rawToken,
        ], content: self::INIT);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $sessionId = $client->getResponse()->headers->get('Mcp-Session-Id');

        $pages = [];
        $cursor = null;
        do {
            $params = null === $cursor ? new \stdClass() : ['cursor' => $cursor];
            $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/mcp', server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$rawToken,
                'HTTP_MCP_SESSION_ID' => $sessionId,
            ], content: json_encode(['jsonrpc' => '2.0', 'id' => \count($pages) + 2, 'method' => 'tools/list', 'params' => $params], \JSON_THROW_ON_ERROR));
            $response = $client->getResponse();
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

            $body = json_decode((string) $response->getContent(), true);
            self::assertIsArray($body['result']['tools']);
            $pages[] = array_values(array_column($body['result']['tools'], 'name'));
            $cursor = $body['result']['nextCursor'] ?? null;
        } while (null !== $cursor && \count($pages) < 10);

        return $pages;
    }

    public function test_request_on_an_unlisted_host_is_rejected_with_a_self_diagnosing_body(): void
    {
        $client = static::createClient();
        $raw = $this->persistValidToken($client);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/mcp', server: [
            'HTTP_HOST' => 'evil.example.com',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
        ], content: self::INIT);

        $response = $client->getResponse();
        self::assertSame(403, $response->getStatusCode());
        self::assertSame(
            "Forbidden: Invalid Host header.\nThe Host header of this request (evil.example.com) is not listed in MCP_ALLOWED_HOSTS, so the MCP endpoint's DNS-rebinding protection rejected it. This is not an authentication failure: add the hostname agents use to reach this instance to MCP_ALLOWED_HOSTS (comma-separated, no port) and restart the app.\n",
            (string) $response->getContent(),
        );
    }

    public function test_request_with_an_unlisted_origin_is_rejected_with_a_self_diagnosing_body(): void
    {
        $client = static::createClient();
        $raw = $this->persistValidToken($client);

        // Origin is checked before Host and short-circuits it, so this is a
        // second rejection path an operator can land on.
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/mcp', server: [
            'HTTP_ORIGIN' => 'https://evil.example.com',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
        ], content: self::INIT);

        $response = $client->getResponse();
        self::assertSame(403, $response->getStatusCode());
        self::assertSame(
            "Forbidden: Invalid Origin header.\nThe Origin header of this request (https://evil.example.com) is not listed in MCP_ALLOWED_HOSTS, so the MCP endpoint's DNS-rebinding protection rejected it. This is not an authentication failure: add the hostname agents use to reach this instance to MCP_ALLOWED_HOSTS (comma-separated, no port) and restart the app.\n",
            (string) $response->getContent(),
        );
    }

    public function test_rejected_origin_is_echoed_truncated(): void
    {
        $client = static::createClient();
        $raw = $this->persistValidToken($client);

        // The echoed value is attacker-controlled, so it is bounded. (Control
        // characters are stripped as well, but HttpFoundation removes those
        // before the listener ever sees the header, so only the length cap is
        // reachable over HTTP.)
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/mcp', server: [
            'HTTP_ORIGIN' => 'https://'.str_repeat('a', 200).'.example.com',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
        ], content: self::INIT);

        $response = $client->getResponse();
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString(
            // 128 characters kept: "https://" plus 120 of the a's.
            '(https://'.str_repeat('a', 120).'...)',
            (string) $response->getContent(),
        );
    }

    /** An access token with the mcp scope, bound to a project of its own owner. */
    private function persistValidToken(KernelBrowser $client): string
    {
        $scenario = new OAuthScenario(static::getContainer());
        $scenario->createClient();
        $user = $scenario->createUser('agent@example.com');

        return $scenario->accessTokenFor($client, $user, 'mcp', $scenario->createProject($user, 'Agent App'));
    }

    /**
     * Every tool in the registry except the ones a feature flag hides. The
     * flags default to off in the test database.
     *
     * @return list<string>
     */
    private static function registeredToolsWithoutGatedOnes(): array
    {
        $registry = self::getContainer()->get('mcp.registry');
        self::assertInstanceOf(Registry::class, $registry);

        $gated = [];
        $handler = self::getContainer()->get(FlagGatedListToolsHandler::class);
        $gatedTools = new \ReflectionProperty($handler, 'gatedTools')->getValue($handler);
        self::assertIsIterable($gatedTools);
        foreach ($gatedTools as $gatedTool) {
            $gated[] = $gatedTool->gatedToolName();
        }

        $names = [];
        foreach ($registry->getTools()->references as $tool) {
            \assert($tool instanceof Tool);
            if (!\in_array($tool->name, $gated, true)) {
                $names[] = $tool->name;
            }
        }
        sort($names);

        return $names;
    }
}
