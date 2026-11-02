<?php

declare(strict_types=1);

namespace App\Tests\Api\RateLimit;

use App\Tests\Support\AgentCredential;
use App\Tests\Support\ApiKeyCredential;
use App\Tests\Support\OAuthScenario;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** Which answers of the public API carry the budget headers, as the reference page states them. */
final class ApiBudgetHeadersTest extends WebTestCase
{
    private KernelBrowser $client;
    private OAuthScenario $scenario;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->scenario = new OAuthScenario(static::getContainer());
    }

    public function test_a_signed_in_request_gets_the_headers_also_on_a_refusal_of_the_route(): void
    {
        $token = ApiKeyCredential::mint(static::getContainer(), $this->scenario->createUser('budget-route@example.com'));

        $this->send(Request::METHOD_GET, '/api/v1/projects', $token);
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->client->getResponse()->headers->has('RateLimit-Limit'));

        $this->send(Request::METHOD_GET, '/api/v1/projects/no-such-project', $token);
        self::assertResponseStatusCodeSame(404);
        self::assertTrue($this->client->getResponse()->headers->has('RateLimit-Limit'));
    }

    public function test_a_request_with_no_credential_gets_no_headers(): void
    {
        foreach (['/api/v1/openapi.json' => 200, '/api/versions' => 200, '/api/v1/projects' => 401] as $path => $status) {
            $this->send(Request::METHOD_GET, $path, null);
            self::assertResponseStatusCodeSame($status, $path);
            self::assertFalse($this->client->getResponse()->headers->has('RateLimit-Limit'), $path);
        }
    }

    public function test_a_refusal_of_the_sign_in_check_gets_no_headers(): void
    {
        $token = AgentCredential::tokenFor(static::getContainer(), $this->scenario->createUser('budget-scope@example.com'), 'mcp');

        $this->send(Request::METHOD_GET, '/api/v1/projects', $token);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->client->getResponse()->headers->has('RateLimit-Limit'));
    }

    public function test_a_path_or_a_method_with_no_route_gets_no_headers(): void
    {
        $token = ApiKeyCredential::mint(static::getContainer(), $this->scenario->createUser('budget-noroute@example.com'));

        $this->send(Request::METHOD_GET, '/api/v1/no-such-path', $token);
        self::assertResponseStatusCodeSame(404);
        self::assertFalse($this->client->getResponse()->headers->has('RateLimit-Limit'));

        $this->send(Request::METHOD_DELETE, '/api/v1/projects', $token);
        self::assertResponseStatusCodeSame(405);
        self::assertFalse($this->client->getResponse()->headers->has('RateLimit-Limit'));
    }

    private function send(string $method, string $path, ?string $token): void
    {
        $this->client->request($method, $path, server: null === $token ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    }
}
