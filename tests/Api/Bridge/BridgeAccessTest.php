<?php

declare(strict_types=1);

namespace App\Tests\Api\Bridge;

use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\AgentCredential;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

final class BridgeAccessTest extends WebTestCase
{
    use BridgeScenario;

    private const string PATH = '/api/v1/bridge/card-holds';

    private const array SAMPLE = [
        '{bridgeId}' => '0f6e6b9e-8f1c-4c4e-9a3a-1d2b3c4d5e6f',
        '{commandId}' => '1f6e6b9e-8f1c-4c4e-9a3a-1d2b3c4d5e6f',
        '{workRequestId}' => '2f6e6b9e-8f1c-4c4e-9a3a-1d2b3c4d5e6f',
        '{sessionId}' => 'session-1',
        '{handle}' => 'loupe',
        '{runId}' => '3f6e6b9e-8f1c-4c4e-9a3a-1d2b3c4d5e6f',
        '{cardId}' => '4f6e6b9e-8f1c-4c4e-9a3a-1d2b3c4d5e6f',
        '{askId}' => '5f6e6b9e-8f1c-4c4e-9a3a-1d2b3c4d5e6f',
        '{experiment}' => 'exp-1',
        '{label}' => 'preview',
    ];

    public function test_an_agent_token_reads_the_card_holds(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $owner = $this->user($this->em(), 'bridge-access-agent@example.com');
        $this->project($this->em(), $owner, 'Bridge access');
        $token = AgentCredential::agentToken(static::getContainer(), $owner);

        $client->request(Request::METHOD_GET, self::PATH, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('{"holds":[]}', $client->getResponse()->getContent());
    }

    public function test_a_token_with_only_the_mcp_scope_is_forbidden_on_every_bridge_route(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $owner = $this->user($this->em(), 'bridge-access-mcp@example.com');
        $project = $this->project($this->em(), $owner, 'Bridge access mcp');
        $token = AgentCredential::tokenFor(static::getContainer(), $owner, 'mcp', $project);
        $router = static::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);

        $checked = 0;
        foreach ($router->getRouteCollection() as $route) {
            if (!str_starts_with($route->getPath(), '/api/v1/bridge/')) {
                continue;
            }
            $method = $route->getMethods()[0] ?? Request::METHOD_GET;
            $path = strtr($route->getPath(), self::SAMPLE);
            $client->request($method, $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
            self::assertResponseStatusCodeSame(403, $method.' '.$path);
            ++$checked;
        }

        self::assertGreaterThan(0, $checked);
    }

    public function test_no_token_is_unauthorized(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, self::PATH);

        self::assertResponseStatusCodeSame(401);
    }

    public function test_the_bridge_path_spends_no_public_budget_header(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $owner = $this->user($this->em(), 'bridge-access-budget@example.com');
        $token = AgentCredential::agentToken(static::getContainer(), $owner);

        $client->request(Request::METHOD_GET, self::PATH, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseIsSuccessful();
        self::assertFalse($client->getResponse()->headers->has('RateLimit-Limit'));
    }
}
