<?php

declare(strict_types=1);

namespace App\Tests\Api\Bridge;

use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\AgentCredential;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class BridgeTwoPathsAccessTest extends WebTestCase
{
    use BridgeScenario;

    private const array PATHS = ['/api/card-holds', '/api/v1/bridge/card-holds'];

    public function test_both_paths_answer_the_same_to_an_agent_token(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $owner = $this->user($this->em(), 'two-paths-agent@example.com');
        $this->project($this->em(), $owner, 'Two paths');
        $token = AgentCredential::agentToken(static::getContainer(), $owner);

        $bodies = [];
        foreach (self::PATHS as $path) {
            $client->request(Request::METHOD_GET, $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
            self::assertResponseStatusCodeSame(200, $path);
            $bodies[] = (string) $client->getResponse()->getContent();
        }

        self::assertSame('{"holds":[]}', $bodies[0]);
        self::assertSame($bodies[0], $bodies[1]);
    }

    public function test_a_token_with_only_the_mcp_scope_is_forbidden_on_both_paths(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $owner = $this->user($this->em(), 'two-paths-mcp@example.com');
        $project = $this->project($this->em(), $owner, 'Two paths mcp');
        $token = AgentCredential::tokenFor(static::getContainer(), $owner, 'mcp', $project);

        foreach (self::PATHS as $path) {
            $client->request(Request::METHOD_GET, $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
            self::assertResponseStatusCodeSame(403, $path);
        }
    }

    public function test_no_token_is_unauthorized_on_both_paths(): void
    {
        $client = static::createClient();

        foreach (self::PATHS as $path) {
            $client->request(Request::METHOD_GET, $path);
            self::assertResponseStatusCodeSame(401, $path);
        }
    }

    public function test_the_new_path_spends_no_public_budget_header(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $owner = $this->user($this->em(), 'two-paths-budget@example.com');
        $token = AgentCredential::agentToken(static::getContainer(), $owner);

        $client->request(Request::METHOD_GET, '/api/v1/bridge/card-holds', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseIsSuccessful();
        self::assertFalse($client->getResponse()->headers->has('RateLimit-Limit'));
    }
}
