<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Project\EventListener\KeepBoundCredentialInItsProject;
use App\Module\Project\Repository\ProjectRepository;
use App\Security\AuthenticatedCredential;
use App\Security\CredentialRateLimitKey;
use App\Tests\Module\Inbox\InboxScenario;
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
use Symfony\Component\Security\Http\AccessMapInterface;
use Symfony\Component\Uid\Uuid;

final class KeepBoundCredentialInItsProjectTest extends WebTestCase
{
    use InboxScenario;

    private KernelBrowser $client;
    private OAuthScenario $scenario;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->audit = RecordingAuditor::installedIn(static::getContainer());
        $this->scenario = new OAuthScenario(static::getContainer());
        $this->setInboxFlag(true);
    }

    public function test_the_binding_holds_on_every_route_whose_access_rule_admits_the_agent_role(): void
    {
        $owner = $this->scenario->createUser('bound-sweep@example.com');
        $first = $this->scenario->createProject($owner, 'First site');
        $second = $this->scenario->createProject($owner, 'Second site');
        $token = AgentCredential::tokenFor(static::getContainer(), $owner, 'agent mcp', $first);

        $router = static::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);
        $accessMap = static::getContainer()->get('security.access_map');
        self::assertInstanceOf(AccessMapInterface::class, $accessMap);

        $swept = 0;
        foreach ($router->getRouteCollection() as $name => $route) {
            $uri = preg_replace_callback(
                '/\{(\w+)\}/',
                static fn (array $m): string => 'handle' === $m[1] ? (string) $second->id : '01a00000-0000-7000-8000-000000000001',
                $route->getPath(),
            );
            self::assertIsString($uri);
            $roles = $accessMap->getPatterns(Request::create($uri))[0] ?? null;
            if (!\is_array($roles) || !\in_array('ROLE_API_AGENT', $roles, true)) {
                continue;
            }

            ++$swept;
            $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'];
            $namesPath = str_contains($route->getPath(), '{handle}');
            if ('api_interactive_session_card_run' === $name) {
                $headers['HTTP_X_LOUPE_PROJECT'] = (string) $second->id;
            }

            $this->client->request($route->getMethods()[0] ?? Request::METHOD_GET, $uri, server: $headers, content: '{}');
            $problem = json_decode((string) $this->client->getResponse()->getContent(), true);

            if ('api_projects' === $name) {
                self::assertResponseStatusCodeSame(200, $name);
                self::assertIsArray($problem);
                self::assertSame([(string) $first->id], array_column($problem['sites'], 'id'), $name);

                continue;
            }

            $expected = $namesPath || 'api_interactive_session_card_run' === $name ? 404 : 403;
            self::assertResponseStatusCodeSame($expected, $name.' let a bound credential reach beyond its project.');
            self::assertSame('application/problem+json', $this->client->getResponse()->headers->get('Content-Type'), $name);
            self::assertIsArray($problem, $name);
            self::assertSame(404 === $expected ? 'project_not_found' : 'credential_not_owner_wide', $problem['code'] ?? null, $name);
            self::assertSame($expected, $problem['status'] ?? null, $name);
        }

        self::assertGreaterThanOrEqual(25, $swept);
    }

    public function test_a_route_with_no_project_refuses_a_bound_credential_and_leaves_one_audit_row(): void
    {
        $owner = $this->scenario->createUser('bound-nonproject@example.com');
        $first = $this->scenario->createProject($owner, 'First site');
        $credential = new AuthenticatedCredential((string) Uuid::v7(), ['ROLE_AGENT'], $first->id);

        $response = $this->invoke($credential, $owner, '/api/v1/bridge/card-holds', null, 'api_card_holds');

        self::assertSame(403, $response?->getStatusCode());
        $record = $this->audit->record('project.agent_route_refused');
        self::assertSame('not_owner_wide', $record->context['reason']);
        self::assertSame((string) $first->id, $record->context['boundProjectId']);
    }

    public function test_the_project_list_passes_for_a_bound_credential(): void
    {
        $owner = $this->scenario->createUser('bound-list@example.com');
        $first = $this->scenario->createProject($owner, 'First site');
        $credential = new AuthenticatedCredential((string) Uuid::v7(), ['ROLE_AGENT'], $first->id);

        self::assertNull($this->invoke($credential, $owner, '/api/v1/bridge/projects', null, 'api_projects'));
        self::assertSame([], $this->audit->records('project.agent_route_refused'));
    }

    public function test_the_card_run_route_follows_the_project_header(): void
    {
        $owner = $this->scenario->createUser('bound-header@example.com');
        $first = $this->scenario->createProject($owner, 'First site');
        $second = $this->scenario->createProject($owner, 'Second site');
        $credential = new AuthenticatedCredential((string) Uuid::v7(), ['ROLE_AGENT'], $first->id);
        $path = '/api/v1/bridge/interactive-sessions/s1/card-run';
        $route = 'api_interactive_session_card_run';

        self::assertNull($this->invoke($credential, $owner, $path, null, $route, (string) $first->id));
        self::assertNull($this->invoke($credential, $owner, $path, null, $route, '  '));
        self::assertSame([], $this->audit->records('project.agent_route_refused'));

        $response = $this->invoke($credential, $owner, $path, null, $route, (string) $second->id);

        self::assertSame(404, $response?->getStatusCode());
        self::assertSame('other_project', $this->audit->record('project.agent_route_refused')->context['reason']);
    }

    public function test_a_route_outside_the_bridge_prefix_that_admits_the_agent_role_is_refused(): void
    {
        $owner = $this->scenario->createUser('bound-prefix@example.com');
        $first = $this->scenario->createProject($owner, 'First site');
        $credential = new AuthenticatedCredential((string) Uuid::v7(), ['ROLE_AGENT'], $first->id);
        $map = new class implements AccessMapInterface {
            /** @return array{0: list<string>, 1: ?string} */
            public function getPatterns(Request $request): array
            {
                return [['ROLE_API_AGENT'], null];
            }
        };

        $response = $this->invoke($credential, $owner, '/api/v2/anything', null, 'api_future', null, $map);

        self::assertSame(403, $response?->getStatusCode());
    }

    public function test_an_owner_wide_and_an_agent_only_credential_pass_every_route(): void
    {
        $owner = $this->scenario->createUser('bound-wide@example.com');
        $wide = new AuthenticatedCredential((string) Uuid::v7(), ['ROLE_AGENT'], allProjects: true);
        $none = new AuthenticatedCredential((string) Uuid::v7(), ['ROLE_AGENT']);

        foreach ([$wide, $none] as $credential) {
            self::assertNull($this->invoke($credential, $owner, '/api/v1/bridge/card-holds', null, 'api_card_holds'));
        }

        self::assertSame([], $this->audit->records('project.agent_route_refused'));
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

        self::assertNull($this->invoke(null, $owner, '/api/v1/bridge/projects/'.$other->id.'/columns', (string) $other->id));
        self::assertSame([], $this->audit->records('project.agent_route_refused'));
    }

    public function test_an_owner_wide_credential_passes_for_any_owned_project(): void
    {
        $owner = $this->scenario->createUser('unit-wide@example.com');
        $this->scenario->createProject($owner, 'First site');
        $second = $this->scenario->createProject($owner, 'Second site');
        $credential = new AuthenticatedCredential((string) Uuid::v7(), ['ROLE_AGENT'], allProjects: true);

        self::assertNull($this->invoke($credential, $owner, '/api/v1/bridge/projects/'.$second->id.'/columns', (string) $second->id));
        self::assertSame([], $this->audit->records('project.agent_route_refused'));
    }

    public function test_a_route_whose_access_rule_does_not_admit_the_agent_role_passes(): void
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

        self::assertNull($this->invoke($credential, $owner, '/api/v1/bridge/projects/'.$first->id.'/columns', (string) $first->id));
        self::assertNull($this->invoke($credential, $owner, '/api/v1/bridge/projects/'.$first->id.'/columns', (string) $first->id));

        $this->expectException(TooManyRequestsHttpException::class);
        $this->invoke($credential, $owner, '/api/v1/bridge/projects/'.$second->id.'/columns', (string) $second->id);
    }

    public function test_an_accepted_request_spends_no_refusal_token(): void
    {
        $owner = $this->scenario->createUser('unit-spend@example.com');
        $first = $this->scenario->createProject($owner, 'First site');
        $credential = new AuthenticatedCredential((string) Uuid::v7(), ['ROLE_AGENT'], $first->id);

        $response = $this->invoke($credential, $owner, '/api/v1/bridge/projects/'.$first->id.'/columns', (string) $first->id);

        self::assertNull($response);
        self::assertSame(30, $this->refusals()->create('token:'.$credential->id)->consume(0)->getRemainingTokens());
    }

    private function invoke(?AuthenticatedCredential $credential, User $user, string $path, ?string $handle, string $route = 'api_project_columns', ?string $header = null, ?AccessMapInterface $map = null): ?Response
    {
        $tokens = new TokenStorage();
        if (null !== $credential) {
            $token = new UsernamePasswordToken($user, 'api', $user->getRoles());
            $token->setAttribute(AuthenticatedCredential::ATTRIBUTE, $credential);
            $tokens->setToken($token);
        }

        $projects = static::getContainer()->get(ProjectRepository::class);
        self::assertInstanceOf(ProjectRepository::class, $projects);
        $map ??= static::getContainer()->get('security.access_map');
        self::assertInstanceOf(AccessMapInterface::class, $map);
        $listener = new KeepBoundCredentialInItsProject($tokens, $projects, $this->audit->auditor, $this->refusals(), new CredentialRateLimitKey($tokens), $map);

        $request = Request::create($path);
        $request->attributes->set('_route', $route);
        if (null !== $handle) {
            $request->attributes->set('handle', $handle);
        }
        if (null !== $header) {
            $request->headers->set('X-Loupe-Project', $header);
        }
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
        $this->client->request(Request::METHOD_GET, '/api/v1/bridge/projects/'.$handle.'/columns', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);
    }
}
