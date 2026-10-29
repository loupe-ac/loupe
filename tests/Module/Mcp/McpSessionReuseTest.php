<?php

declare(strict_types=1);

namespace App\Tests\Module\Mcp;

use App\Tests\Support\OAuthScenario;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class McpSessionReuseTest extends WebTestCase
{
    private const string INIT = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}';

    private const string LIST = '{"jsonrpc":"2.0","id":2,"method":"tools/list"}';

    public function test_a_session_is_reused_after_the_kernel_restarts(): void
    {
        $client = static::createClient();
        $token = $this->token($client);

        $this->post($client, $token, self::INIT, null);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $sessionId = $client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertNotNull($sessionId);

        $client->getKernel()->shutdown();
        $this->post($client, $token, self::LIST, $sessionId);

        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
    }

    public function test_an_unknown_session_gets_a_bare_404(): void
    {
        $client = static::createClient();
        $token = $this->token($client);

        $this->post($client, $token, self::LIST, '00000000-0000-4000-8000-000000000000');

        $response = $client->getResponse();
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('', (string) $response->getContent());
        self::assertSame('0', $response->headers->get('Content-Length'));
    }

    public function test_a_404_without_a_session_id_keeps_its_body(): void
    {
        $client = static::createClient();
        $token = $this->token($client);

        $client->request(Request::METHOD_GET, '/mcp/no-such-route', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertNotSame('', (string) $client->getResponse()->getContent());
    }

    private function post(KernelBrowser $client, string $token, string $body, ?string $sessionId): void
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token];
        if (null !== $sessionId) {
            $server['HTTP_MCP_SESSION_ID'] = $sessionId;
        }

        $client->request(Request::METHOD_POST, '/mcp', server: $server, content: $body);
    }

    private function token(KernelBrowser $client): string
    {
        $scenario = new OAuthScenario(static::getContainer());
        $scenario->createClient();
        $user = $scenario->createUser('reuse@example.com');

        return $scenario->accessTokenFor($client, $user, 'mcp', $scenario->createProject($user, 'Reuse App'));
    }
}
