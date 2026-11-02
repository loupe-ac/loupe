<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Project\EventListener\KeepBoundCredentialInItsProject;
use App\Module\Project\Repository\ProjectRepository;
use App\Security\AuthenticatedCredential;
use App\Security\CredentialRateLimitKey;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\OAuthScenario;
use App\Tests\Support\RecordingAuditor;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

final class KeepBoundCredentialInItsProjectTest extends WebTestCase
{
    private KernelBrowser $client;
    private OAuthScenario $scenario;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->audit = RecordingAuditor::installedIn(static::getContainer());
        $this->scenario = new OAuthScenario(static::getContainer());
    }

    public function test_a_bound_credential_gets_not_found_for_every_project_route_of_another_owned_project(): void
    {
        $owner = $this->scenario->createUser('bound-sweep@example.com');
        $first = $this->scenario->createProject($owner, 'First site');
        $second = $this->scenario->createProject($owner, 'Second site');
        $token = AgentCredential::tokenFor(static::getContainer(), $owner, 'agent mcp', $first);

        $router = static::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);

        $swept = 0;
        foreach ($router->getRouteCollection() as $name => $route) {
            if (!str_starts_with($route->getPath(), '/api/projects/{handle}') && !str_starts_with($route->getPath(), '/api/v1/bridge/projects/{handle}')) {
                continue;
            }

            ++$swept;
            $uri = preg_replace_callback(
                '/\{(\w+)\}/',
                static fn (array $m): string => 'handle' === $m[1] ? (string) $second->id : '01a00000-0000-7000-8000-000000000001',
                $route->getPath(),
            );
            self::assertIsString($uri);

            $this->client->request($route->getMethods()[0] ?? Request::METHOD_GET, $uri, server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                'CONTENT_TYPE' => 'application/json',
            ], content: '{}');

            self::assertResponseStatusCodeSame(404, $name.' let a bound credential reach another project.');
            self::assertSame('{"error":"project_not_found"}', $this->client->getResponse()->getContent(), $name);
        }

        self::assertGreaterThanOrEqual(26, $swept);
    }

    public function test_a_refusal_leaves_a_security_audit_row(): void
    {
        $owner = $this->scenario->createUser('bound-audit@example.com');
        $first = $this->scenario->createProject($owner, 'First site');
        $second = $this->scenario->createProject($owner, 'Second site');

        $this->get(AgentCredential::tokenFor(static::getContainer(), $owner, 'agent mcp', $first), (string) $second->id);

        self::assertResponseStatusCodeSame(404);
        $record = $this->audit->record('project.agent_route_refused');
        self::assertSame((string) $first->id, $record->context['boundProjectId']);
        self::assertSame((string) $second->id, $record->context['handle']);
    }

    public function test_a_handle_that_finds_nothing_is_refused_for_a_bound_credential(): void
    {
        $owner = $this->scenario->createUser('bound-missing@example.com');
        $first = $this->scenario->createProject($owner, 'First site');

        $this->get(AgentCredential::tokenFor(static::getContainer(), $owner, 'agent mcp', $first), 'no-such-project');

        self::assertResponseStatusCodeSame(404);
        self::assertCount(1, $this->audit->records('project.agent_route_refused'));
    }

    public function test_a_bound_credential_reads_its_own_project_by_id_and_by_slug(): void
    {
        $owner = $this->scenario->createUser('bound-own@example.com');
        $first = $this->scenario->createProject($owner, 'First site');
        $this->scenario->createProject($owner, 'Second site');
        $token = AgentCredential::tokenFor(static::getContainer(), $owner, 'agent mcp', $first);

        $this->get($token, (string) $first->id);
        self::assertResponseStatusCodeSame(200);

        $this->get($token, $first->slug ?? self::fail('the project has no slug.'));
        self::assertResponseStatusCodeSame(200);

        self::assertSame([], $this->audit->records('project.agent_route_refused'));
    }

    public function test_an_agent_only_credential_keeps_every_owned_project(): void
    {
        $owner = $this->scenario->createUser('bound-none@example.com');
        $this->scenario->createProject($owner, 'First site');
        $second = $this->scenario->createProject($owner, 'Second site');

        $this->get(AgentCredential::agentToken(static::getContainer(), $owner), (string) $second->id);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->audit->records('project.agent_route_refused'));
    }

    public function test_refusals_over_the_limit_get_too_many_requests_and_no_audit_row(): void
    {
        $owner = $this->scenario->createUser('bound-limit@example.com');
        $first = $this->scenario->createProject($owner, 'First site');
        $second = $this->scenario->createProject($owner, 'Second site');
        $token = AgentCredential::tokenFor(static::getContainer(), $owner, 'agent mcp', $first);

        for ($i = 0; $i < 30; ++$i) {
            $this->get($token, (string) $second->id);
            self::assertResponseStatusCodeSame(404);
        }

        $this->get($token, (string) $second->id);

        self::assertResponseStatusCodeSame(429);
        self::assertCount(30, $this->audit->records('project.agent_route_refused'));
    }

    public function test_a_request_with_no_credential_passes(): void
    {
        $owner = $this->scenario->createUser('unit-none@example.com');
        $other = $this->scenario->createProject($owner, 'Other site');

        self::assertNull($this->invoke(null, $owner, '/api/projects/'.$other->id.'/board/columns', (string) $other->id));
        self::assertSame([], $this->audit->records('project.agent_route_refused'));
    }

    public function test_an_owner_wide_credential_passes_for_any_owned_project(): void
    {
        $owner = $this->scenario->createUser('unit-wide@example.com');
        $this->scenario->createProject($owner, 'First site');
        $second = $this->scenario->createProject($owner, 'Second site');
        $credential = new AuthenticatedCredential((string) Uuid::v7(), ['ROLE_AGENT'], allProjects: true);

        self::assertNull($this->invoke($credential, $owner, '/api/projects/'.$second->id.'/board/columns', (string) $second->id));
        self::assertSame([], $this->audit->records('project.agent_route_refused'));
    }

    public function test_a_path_outside_the_project_routes_passes_for_a_bound_credential(): void
    {
        $owner = $this->scenario->createUser('unit-outside@example.com');
        $first = $this->scenario->createProject($owner, 'First site');
        $second = $this->scenario->createProject($owner, 'Second site');
        $credential = new AuthenticatedCredential((string) Uuid::v7(), ['ROLE_AGENT'], $first->id);

        self::assertNull($this->invoke($credential, $owner, '/api/other/'.$second->id, (string) $second->id));
        self::assertSame([], $this->audit->records('project.agent_route_refused'));
    }

    public function test_an_accepted_request_passes_with_an_empty_refusal_bucket(): void
    {
        $owner = $this->scenario->createUser('unit-empty@example.com');
        $first = $this->scenario->createProject($owner, 'First site');
        $second = $this->scenario->createProject($owner, 'Second site');
        $credential = new AuthenticatedCredential((string) Uuid::v7(), ['ROLE_AGENT'], $first->id);
        $this->refusals()->create('token:'.$credential->id)->consume(30);

        self::assertNull($this->invoke($credential, $owner, '/api/projects/'.$first->id.'/board/columns', (string) $first->id));
        self::assertNull($this->invoke($credential, $owner, '/api/projects/'.$first->id.'/board/columns', (string) $first->id));

        $this->expectException(TooManyRequestsHttpException::class);
        $this->invoke($credential, $owner, '/api/projects/'.$second->id.'/board/columns', (string) $second->id);
    }

    public function test_an_accepted_request_spends_no_refusal_token(): void
    {
        $owner = $this->scenario->createUser('unit-spend@example.com');
        $first = $this->scenario->createProject($owner, 'First site');
        $credential = new AuthenticatedCredential((string) Uuid::v7(), ['ROLE_AGENT'], $first->id);

        $response = $this->invoke($credential, $owner, '/api/projects/'.$first->id.'/board/columns', (string) $first->id);

        self::assertNull($response);
        self::assertSame(30, $this->refusals()->create('token:'.$credential->id)->consume(0)->getRemainingTokens());
    }

    private function invoke(?AuthenticatedCredential $credential, User $user, string $path, string $handle): ?Response
    {
        $tokens = new TokenStorage();
        if (null !== $credential) {
            $token = new UsernamePasswordToken($user, 'api', $user->getRoles());
            $token->setAttribute(AuthenticatedCredential::ATTRIBUTE, $credential);
            $tokens->setToken($token);
        }

        $projects = static::getContainer()->get(ProjectRepository::class);
        self::assertInstanceOf(ProjectRepository::class, $projects);
        $listener = new KeepBoundCredentialInItsProject($tokens, $projects, $this->audit->auditor, $this->refusals(), new CredentialRateLimitKey($tokens));

        $request = Request::create($path);
        $request->attributes->set('handle', $handle);
        $event = new RequestEvent($this->client->getKernel(), $request, HttpKernelInterface::MAIN_REQUEST);
        $listener($event);

        return $event->getResponse();
    }

    private function refusals(): RateLimiterFactoryInterface
    {
        $factory = static::getContainer()->get('limiter.agent_bound_project_refusals');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $factory);

        return $factory;
    }

    private function get(string $token, string $handle): void
    {
        $this->client->request(Request::METHOD_GET, '/api/projects/'.$handle.'/board/columns', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);
    }
}
