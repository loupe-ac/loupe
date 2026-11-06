<?php

declare(strict_types=1);

namespace App\Tests\Api\Bridge;

use App\Outbox\AgentPush;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\AgentCredential;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BridgeProblemResponsesTest extends WebTestCase
{
    use BridgeScenario;

    private const string UNKNOWN_UUID = '0b0e4a8c-6f3e-4b55-9d0a-1c2d3e4f5a6b';

    /** @return iterable<string, array{string, string, string, int, string, string}> */
    public static function refusals(): iterable
    {
        yield 'board columns of an unknown project' => ['GET', '/api/v1/bridge/projects/no-such/columns', '', 404, 'project_not_found', 'Project not found'];
        yield 'card of an unknown project' => ['GET', '/api/v1/bridge/projects/no-such/cards/'.self::UNKNOWN_UUID, '', 404, 'project_not_found', 'Project not found'];
        yield 'preview token of an unknown project' => ['DELETE', '/api/v1/bridge/projects/no-such/preview-tokens/label', '', 404, 'project_not_found', 'Project not found'];
        yield 'git token of an unknown project' => ['POST', '/api/v1/bridge/projects/no-such/git-token', '{"repository":"acme/site"}', 404, 'project_not_found', 'Project not found'];
        yield 'claim by an unknown bridge' => ['POST', '/api/v1/bridge/bridges/'.self::UNKNOWN_UUID.'/work-requests/'.self::UNKNOWN_UUID.'/claim', '{}', 422, 'unknown_bridge', 'Unknown bridge'];
        yield 'card run with no project header' => ['GET', '/api/v1/bridge/interactive-sessions/'.self::UNKNOWN_UUID.'/card-run', '', 422, 'project_header_required', 'Project header required'];
    }

    #[DataProvider('refusals')]
    public function test_a_refusal_is_a_problem(
        string $method,
        string $path,
        string $body,
        int $status,
        string $code,
        string $title,
    ): void {
        $client = static::createClient();
        $client->disableReboot();
        $owner = $this->user($this->em(), 'problem-refusal@example.com');
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.AgentCredential::agentToken(static::getContainer(), $owner), 'CONTENT_TYPE' => 'application/json'];

        $client->request($method, $path, server: $server, content: $body);
        self::assertResponseStatusCodeSame($status);
        self::assertSame('application/problem+json', $client->getResponse()->headers->get('Content-Type'));
        $problem = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($problem);
        self::assertSame('https://loupe.ac/docs/reference/api/errors#'.$code, $problem['type']);
        self::assertSame($title, $problem['title']);
        self::assertSame($status, $problem['status']);
        self::assertSame($code, $problem['code']);
        self::assertIsString($problem['detail']);
        self::assertNotSame('', $problem['detail']);
    }

    public function test_the_events_upgrade_answer_names_its_reason(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $owner = $this->user($this->em(), 'problem-events@example.com');
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.AgentCredential::agentToken(static::getContainer(), $owner)];

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/api/v1/bridge/events', server: $server);
        self::assertResponseStatusCodeSame(426);
        self::assertSame('application/problem+json', $client->getResponse()->headers->get('Content-Type'));
        $problem = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($problem);
        self::assertSame('no_bridge_id', $problem['code']);
        self::assertSame('Upgrade required', $problem['title']);
        self::assertStringContainsString('loupe CLI', $problem['detail']);
    }

    public function test_a_missing_token_is_invalid_credentials(): void
    {
        $client = static::createClient();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/api/v1/bridge/card-holds');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('application/problem+json', $client->getResponse()->headers->get('Content-Type'));
        $problem = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($problem);
        self::assertSame('invalid_credentials', $problem['code']);
    }

    public function test_a_route_behind_a_flag_that_is_off_answers_http_404(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();
        $owner = $this->user($em, 'problem-flag-off@example.com');
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.AgentCredential::agentToken(static::getContainer(), $owner), 'CONTENT_TYPE' => 'application/json'];
        $em->getConnection()->executeStatement("UPDATE feature_flag SET value = 'false' WHERE name = ?", [AgentPush::FLAG]);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_PUT, '/api/v1/bridge/bridges/'.self::UNKNOWN_UUID.'/heartbeat', server: $server, content: '{}');

        self::assertResponseStatusCodeSame(404);
        $problem = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($problem);
        self::assertSame('http_404', $problem['code']);
    }

    public function test_a_token_of_the_wrong_scope_is_credential_not_allowed(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $owner = $this->user($this->em(), 'problem-scope@example.com');
        $project = $this->project($this->em(), $owner, 'Problem scope');
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.AgentCredential::tokenFor(static::getContainer(), $owner, 'mcp', $project)];

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/api/v1/bridge/card-holds', server: $server);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('application/problem+json', $client->getResponse()->headers->get('Content-Type'));
        $problem = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($problem);
        self::assertSame('credential_not_allowed', $problem['code']);
    }
}
