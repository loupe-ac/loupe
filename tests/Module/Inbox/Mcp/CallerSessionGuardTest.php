<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Exception\DomainErrors;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\CallerRun;
use App\Module\Inbox\Mcp\CallerSessionGuard;
use App\Security\AuthenticatedCredential;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Uid\Uuid;

final class CallerSessionGuardTest extends TestCase
{
    public function test_a_call_with_no_header_passes_for_any_session(): void
    {
        $this->guard(null)->requireOwnSession(Uuid::v4());
        $this->addToAssertionCount(1);
    }

    public function test_a_call_with_no_request_passes(): void
    {
        $this->guardFor(new RequestStack(), null)->requireOwnSession(Uuid::v4());
        $this->addToAssertionCount(1);
    }

    public function test_a_header_that_equals_the_session_passes(): void
    {
        $session = Uuid::v4();

        $this->guard((string) $session)->requireOwnSession($session);
        $this->addToAssertionCount(1);
    }

    public function test_a_header_that_names_another_session_is_refused(): void
    {
        $this->assertRefused(fn () => $this->guard((string) Uuid::v4())->requireOwnSession(Uuid::v4()));
    }

    public function test_a_header_that_is_not_a_uuid_is_refused(): void
    {
        $this->expectException(DomainErrors::class);
        $this->guard('not-a-uuid')->requireOwnSession(Uuid::v4());
    }

    public function test_a_credential_session_that_equals_the_session_passes_without_a_header(): void
    {
        $session = Uuid::v4();

        $this->guard(null, $session)->requireOwnSession($session);
        $this->addToAssertionCount(1);
    }

    public function test_a_credential_session_that_differs_from_the_session_is_refused(): void
    {
        $this->assertRefused(fn () => $this->guard(null, Uuid::v4())->requireOwnSession(Uuid::v4()));
    }

    public function test_a_credential_wins_over_a_conflicting_header(): void
    {
        $session = Uuid::v4();

        $this->guard((string) Uuid::v4(), $session)->requireOwnSession($session);
        $this->addToAssertionCount(1);
    }

    public function test_a_credential_wins_over_a_header_that_names_the_argument(): void
    {
        $session = Uuid::v4();

        $this->assertRefused(fn () => $this->guard((string) $session, Uuid::v4())->requireOwnSession($session));
    }

    private function assertRefused(callable $call): void
    {
        try {
            $call();
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['sessionId' => CallerSessionGuard::NOT_CALLER], $e->errors);
        }
    }

    private function guard(?string $header, ?Uuid $credentialSession = null): CallerSessionGuard
    {
        $request = Request::create('/mcp', Request::METHOD_POST);
        if (null !== $header) {
            $request->headers->set(CallerRun::SESSION_HEADER, $header);
        }
        $requests = new RequestStack();
        $requests->push($request);

        return $this->guardFor($requests, $credentialSession);
    }

    private function guardFor(RequestStack $requests, ?Uuid $credentialSession): CallerSessionGuard
    {
        $tokens = new TokenStorage();
        if (null !== $credentialSession) {
            $token = new PostAuthenticationToken(new InMemoryUser('worker', null, ['ROLE_API_MCP']), 'api', ['ROLE_API_MCP']);
            $token->setAttribute(AuthenticatedCredential::ATTRIBUTE, new AuthenticatedCredential(
                id: 'worker-run',
                roles: ['ROLE_API_MCP'],
                workerBridgeId: Uuid::v4(),
                workerRunKey: Uuid::v4(),
                workerSessionId: $credentialSession,
            ));
            $tokens->setToken($token);
        }

        return new CallerSessionGuard($requests, new CallerRun($tokens, $requests, $this->createStub(WorkerRunRepository::class)));
    }
}
