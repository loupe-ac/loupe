<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\CallerRun;
use App\Module\Inbox\Mcp\WorkerSessionGuard;
use App\Module\Project\Entity\Project;
use App\Security\AuthenticatedCredential;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Uid\Uuid;

final class WorkerSessionGuardTest extends TestCase
{
    private const string SESSION = '0192f0a4-7c1e-7d3b-9a52-3f1d2c4b5a69';

    private WorkerRunRepository&Stub $workerRuns;
    private RequestStack $requests;
    private TokenStorage $tokens;
    private WorkerSessionGuard $guard;

    protected function setUp(): void
    {
        $this->workerRuns = $this->createStub(WorkerRunRepository::class);
        $this->requests = new RequestStack();
        $this->tokens = new TokenStorage();
        $this->useRepository($this->workerRuns);
    }

    public function test_a_session_of_a_worker_run_is_refused(): void
    {
        $project = $this->createStub(Project::class);
        $this->callWith(self::SESSION);
        $this->mockRepository()->expects($this->once())
            ->method('findNewestWorkerOfSession')
            ->with($project, Uuid::fromString(self::SESSION))
            ->willReturn($this->createStub(WorkerRun::class));

        $this->assertRefused($project);
    }

    public function test_a_run_credential_is_refused_without_a_header(): void
    {
        $this->credential();
        $this->callWith(null);
        $this->mockRepository()->expects($this->never())->method('findNewestWorkerOfSession');

        $this->assertRefused($this->createStub(Project::class));
    }

    public function test_a_run_credential_is_refused_with_a_header_that_names_no_worker_session(): void
    {
        $this->credential();
        $this->callWith(self::SESSION);
        $this->workerRuns->method('findNewestWorkerOfSession')->willReturn(null);

        $this->assertRefused($this->createStub(Project::class));
    }

    public function test_a_session_with_no_worker_run_passes(): void
    {
        $this->callWith(self::SESSION);
        $this->mockRepository()->expects($this->once())->method('findNewestWorkerOfSession')->willReturn(null);

        $this->guard->requireNotWorker($this->createStub(Project::class));
    }

    public function test_a_call_with_no_header_passes(): void
    {
        $this->callWith(null);
        $this->mockRepository()->expects($this->never())->method('findNewestWorkerOfSession');

        $this->guard->requireNotWorker($this->createStub(Project::class));
    }

    public function test_a_call_with_no_request_passes(): void
    {
        $this->mockRepository()->expects($this->never())->method('findNewestWorkerOfSession');

        $this->guard->requireNotWorker($this->createStub(Project::class));
    }

    #[DataProvider('invalidSessions')]
    public function test_a_header_that_is_not_a_uuid_passes(string $value): void
    {
        $this->callWith($value);
        $this->mockRepository()->expects($this->never())->method('findNewestWorkerOfSession');

        $this->guard->requireNotWorker($this->createStub(Project::class));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSessions(): iterable
    {
        yield 'a word' => ['not-a-uuid'];
        yield 'an empty value' => [''];
    }

    private function useRepository(WorkerRunRepository $workerRuns): void
    {
        $this->guard = new WorkerSessionGuard(new CallerRun($this->tokens, $this->requests, $workerRuns));
    }

    private function mockRepository(): WorkerRunRepository&MockObject
    {
        $mock = $this->createMock(WorkerRunRepository::class);
        $this->useRepository($mock);

        return $mock;
    }

    private function assertRefused(Project $project): void
    {
        try {
            $this->guard->requireNotWorker($project);
        } catch (ToolCallException $e) {
            self::assertSame('A bridge worker cannot answer an inbox item. Ask the owner with inbox_ask.', $e->getMessage());

            return;
        }

        self::fail('The guard let a worker call through.');
    }

    private function credential(): void
    {
        $token = new PostAuthenticationToken(new InMemoryUser('worker', null, ['ROLE_API_MCP']), 'api', ['ROLE_API_MCP']);
        $token->setAttribute(AuthenticatedCredential::ATTRIBUTE, new AuthenticatedCredential(
            id: 'worker-run',
            roles: ['ROLE_API_MCP'],
            workerBridgeId: Uuid::v4(),
            workerRunKey: Uuid::v4(),
            workerSessionId: Uuid::v4(),
        ));
        $this->tokens->setToken($token);
    }

    private function callWith(?string $session): void
    {
        $request = new Request();
        if (null !== $session) {
            $request->headers->set(CallerRun::SESSION_HEADER, $session);
        }
        $this->requests->push($request);
    }
}
