<?php

declare(strict_types=1);

namespace App\Tests\Api\RateLimit;

use App\Api\EventListener\ApplyApiBudgetOnRequest;
use App\Api\EventListener\SendApiBudgetHeadersOnResponse;
use App\Api\Problem\ApiProblemException;
use App\Api\RateLimit\ApiBudget;
use App\Security\AuthenticatedCredential;
use App\Security\CredentialRateLimitKey;
use App\Tests\Support\FeatureFlags;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class ApplyApiBudgetOnRequestTest extends TestCase
{
    public function test_a_credential_spends_its_read_budget_and_gets_the_budget_headers(): void
    {
        $listener = $this->listener(['api.rate_limit_reads_per_minute' => 5], 'cred-1');
        $request = Request::create('https://loupe.test/api/v1/projects');

        $this->handle($listener, $request);
        $response = $this->respond($request);

        self::assertSame('5', $response->headers->get('RateLimit-Limit'));
        self::assertSame('4', $response->headers->get('RateLimit-Remaining'));
        self::assertMatchesRegularExpression('/^\d+$/', (string) $response->headers->get('RateLimit-Reset'));
    }

    public function test_the_window_refuses_a_request_past_the_budget_with_a_retry_after(): void
    {
        $listener = $this->listener(['api.rate_limit_reads_per_minute' => 2], 'cred-2');

        $this->handle($listener, Request::create('https://loupe.test/api/v1/projects'));
        $this->handle($listener, Request::create('https://loupe.test/api/v1/projects'));

        try {
            $this->handle($listener, Request::create('https://loupe.test/api/v1/projects'));
            self::fail('The third request was accepted.');
        } catch (ApiProblemException $exception) {
            self::assertSame(429, $exception->problem->status);
            self::assertSame('rate_limited', $exception->problem->code);
            self::assertGreaterThanOrEqual(1, (int) $exception->problem->headers['Retry-After']);
            self::assertSame('0', $exception->problem->headers['RateLimit-Remaining']);
            self::assertSame('2', $exception->problem->headers['RateLimit-Limit']);
        }
    }

    public function test_writes_have_their_own_smaller_budget(): void
    {
        $listener = $this->listener(['api.rate_limit_reads_per_minute' => 100, 'api.rate_limit_writes_per_minute' => 1], 'cred-3');

        $this->handle($listener, Request::create('https://loupe.test/api/v1/projects/x/cards', Request::METHOD_POST));
        $this->handle($listener, Request::create('https://loupe.test/api/v1/projects'));

        $this->expectException(ApiProblemException::class);
        $this->handle($listener, Request::create('https://loupe.test/api/v1/projects/x/cards', Request::METHOD_POST));
    }

    public function test_the_coded_defaults_apply_when_no_flag_row_exists(): void
    {
        $budget = new ApiBudget(FeatureFlags::service(), new ArrayAdapter());

        self::assertSame(600, $budget->reads());
        self::assertSame(120, $budget->writes());
    }

    public function test_a_zero_or_negative_flag_falls_back_to_the_default(): void
    {
        $budget = new ApiBudget(FeatureFlags::service(['api.rate_limit_reads_per_minute' => 0, 'api.rate_limit_writes_per_minute' => -3]), new ArrayAdapter());

        self::assertSame(600, $budget->reads());
        self::assertSame(120, $budget->writes());
    }

    public function test_an_anonymous_route_spends_from_the_address_and_sends_no_budget_headers(): void
    {
        $listener = $this->listener(['api.rate_limit_reads_per_minute' => 1], null);
        $request = Request::create('https://loupe.test/api/versions', server: ['REMOTE_ADDR' => '203.0.113.9']);

        $this->handle($listener, $request);
        self::assertFalse($this->respond($request)->headers->has('RateLimit-Limit'));

        try {
            $this->handle($listener, Request::create('https://loupe.test/api/versions', server: ['REMOTE_ADDR' => '203.0.113.9']));
            self::fail('The second request was accepted.');
        } catch (ApiProblemException $exception) {
            self::assertArrayHasKey('Retry-After', $exception->problem->headers);
            self::assertArrayNotHasKey('RateLimit-Limit', $exception->problem->headers);
        }
    }

    public function test_the_bridge_widget_and_old_paths_keep_their_own_limits(): void
    {
        $listener = $this->listener(['api.rate_limit_reads_per_minute' => 1], 'cred-4');

        foreach (['/api/v1/bridge/card-holds', '/api/v1/live-review-widget/review', '/api/projects', '/api/events'] as $path) {
            for ($i = 0; $i < 3; ++$i) {
                $this->handle($listener, Request::create('https://loupe.test'.$path));
            }
        }

        $this->addToAssertionCount(1);
    }

    /** @param array<string, int> $flags */
    private function listener(array $flags, ?string $credentialId): ApplyApiBudgetOnRequest
    {
        $tokens = new TokenStorage();
        if (null !== $credentialId) {
            $token = new UsernamePasswordToken(new InMemoryUser('caller', null), 'api', ['ROLE_USER']);
            $token->setAttribute(AuthenticatedCredential::ATTRIBUTE, new AuthenticatedCredential($credentialId, ['ROLE_API_AGENT']));
            $tokens->setToken($token);
        }

        return new ApplyApiBudgetOnRequest(
            new ApiBudget(FeatureFlags::service($flags), new ArrayAdapter()),
            new CredentialRateLimitKey($tokens),
            $tokens,
        );
    }

    private function handle(ApplyApiBudgetOnRequest $listener, Request $request): void
    {
        $listener(new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));
    }

    private function respond(Request $request): Response
    {
        $response = new Response();
        new SendApiBudgetHeadersOnResponse()(new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));

        return $response;
    }
}
