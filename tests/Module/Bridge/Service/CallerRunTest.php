<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\CallerRun;
use App\Module\Project\Entity\Project;
use App\Security\AuthenticatedCredential;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Uid\Uuid;

final class CallerRunTest extends TestCase
{
    private RequestStack $requests;
    private TokenStorage $tokens;

    protected function setUp(): void
    {
        $this->requests = new RequestStack();
        $this->tokens = new TokenStorage();
    }

    public function test_a_run_credential_names_its_bridge_run_key_and_session(): void
    {
        $workerRuns = $this->createStub(WorkerRunRepository::class);
        $bridgeId = Uuid::v4();
        $runKey = Uuid::v4();
        $session = Uuid::v4();
        $this->credential(new AuthenticatedCredential('worker-run', ['ROLE_API_MCP'], workerBridgeId: $bridgeId, workerRunKey: $runKey, workerSessionId: $session));

        $key = $this->callerRun($workerRuns)->workerRunKey();

        self::assertNotNull($key);
        self::assertTrue($bridgeId->equals($key->bridgeId));
        self::assertTrue($runKey->equals($key->runKey));
        self::assertEquals($session, $this->callerRun($workerRuns)->workerSession());
    }

    public function test_a_call_with_no_token_has_no_run_key_and_no_session(): void
    {
        $workerRuns = $this->createStub(WorkerRunRepository::class);
        self::assertNull($this->callerRun($workerRuns)->workerRunKey());
        self::assertNull($this->callerRun($workerRuns)->workerSession());
    }

    public function test_a_credential_without_a_run_has_no_run_key_and_no_session(): void
    {
        $workerRuns = $this->createStub(WorkerRunRepository::class);
        $this->credential(new AuthenticatedCredential('plain', ['ROLE_API_MCP']));

        self::assertNull($this->callerRun($workerRuns)->workerRunKey());
        self::assertNull($this->callerRun($workerRuns)->workerSession());
    }

    public function test_a_credential_with_a_bridge_but_no_run_key_has_no_run_key(): void
    {
        $workerRuns = $this->createStub(WorkerRunRepository::class);
        $this->credential(new AuthenticatedCredential('half', ['ROLE_API_MCP'], workerBridgeId: Uuid::v4()));

        self::assertNull($this->callerRun($workerRuns)->workerRunKey());
    }

    public function test_the_run_is_the_one_the_repository_finds_for_the_credential(): void
    {
        $workerRuns = $this->createMock(WorkerRunRepository::class);
        $bridgeId = Uuid::v4();
        $runKey = Uuid::v4();
        $project = $this->createStub(Project::class);
        $run = $this->createStub(WorkerRun::class);
        $this->credential(new AuthenticatedCredential('worker-run', ['ROLE_API_MCP'], workerBridgeId: $bridgeId, workerRunKey: $runKey, workerSessionId: Uuid::v4()));
        $workerRuns->expects($this->once())
            ->method('findWorkerByRunKey')
            ->with($project, $this->callback(static fn (Uuid $id): bool => $id->equals($bridgeId)), $this->callback(static fn (Uuid $key): bool => $key->equals($runKey)))
            ->willReturn($run);

        self::assertSame($run, $this->callerRun($workerRuns)->workerRun($project));
    }

    public function test_a_credential_whose_run_is_not_reported_names_no_run(): void
    {
        $workerRuns = $this->createStub(WorkerRunRepository::class);
        $this->credential(new AuthenticatedCredential('worker-run', ['ROLE_API_MCP'], workerBridgeId: Uuid::v4(), workerRunKey: Uuid::v4(), workerSessionId: Uuid::v4()));
        $workerRuns->method('findWorkerByRunKey')->willReturn(null);

        self::assertNull($this->callerRun($workerRuns)->workerRun($this->createStub(Project::class)));
    }

    public function test_a_header_alone_names_no_run_and_no_session(): void
    {
        $workerRuns = $this->createMock(WorkerRunRepository::class);
        $this->push(Uuid::v4()->toRfc4122());
        $workerRuns->expects($this->never())->method('findWorkerByRunKey');

        self::assertNull($this->callerRun($workerRuns)->workerRun($this->createStub(Project::class)));
        self::assertNull($this->callerRun($workerRuns)->workerRunKey());
        self::assertNull($this->callerRun($workerRuns)->workerSession());
    }

    public function test_a_call_with_no_credential_names_no_run_and_asks_no_repository(): void
    {
        $workerRuns = $this->createMock(WorkerRunRepository::class);
        $workerRuns->expects($this->never())->method('findWorkerByRunKey');

        self::assertNull($this->callerRun($workerRuns)->workerRun($this->createStub(Project::class)));
    }

    public function test_a_credential_marks_the_call_as_a_worker_call_whatever_the_header_says(): void
    {
        $workerRuns = $this->createMock(WorkerRunRepository::class);
        $this->credential(new AuthenticatedCredential('worker-run', ['ROLE_API_MCP'], workerBridgeId: Uuid::v4(), workerRunKey: Uuid::v4(), workerSessionId: Uuid::v4()));
        $this->push(null);
        $workerRuns->expects($this->never())->method('findNewestWorkerOfSession');

        self::assertTrue($this->callerRun($workerRuns)->isWorker($this->createStub(Project::class)));
    }

    public function test_a_header_that_names_a_worker_session_of_the_project_marks_the_call_as_a_worker_call(): void
    {
        $workerRuns = $this->createMock(WorkerRunRepository::class);
        $session = Uuid::v4();
        $project = $this->createStub(Project::class);
        $this->push($session->toRfc4122());
        $workerRuns->expects($this->once())
            ->method('findNewestWorkerOfSession')
            ->with($project, $this->callback(static fn (Uuid $id): bool => $id->equals($session)))
            ->willReturn($this->createStub(WorkerRun::class));

        self::assertTrue($this->callerRun($workerRuns)->isWorker($project));
    }

    public function test_a_header_that_names_no_worker_run_leaves_the_call_a_non_worker_call(): void
    {
        $workerRuns = $this->createMock(WorkerRunRepository::class);
        $this->push(Uuid::v4()->toRfc4122());
        $workerRuns->expects($this->once())->method('findNewestWorkerOfSession')->willReturn(null);

        self::assertFalse($this->callerRun($workerRuns)->isWorker($this->createStub(Project::class)));
    }

    public function test_a_header_that_is_not_a_uuid_leaves_the_call_a_non_worker_call(): void
    {
        $workerRuns = $this->createMock(WorkerRunRepository::class);
        $this->push('not-a-uuid');
        $workerRuns->expects($this->never())->method('findNewestWorkerOfSession');

        self::assertFalse($this->callerRun($workerRuns)->isWorker($this->createStub(Project::class)));
    }

    public function test_a_call_with_no_request_and_no_credential_is_a_non_worker_call(): void
    {
        $workerRuns = $this->createMock(WorkerRunRepository::class);
        $workerRuns->expects($this->never())->method('findNewestWorkerOfSession');

        self::assertFalse($this->callerRun($workerRuns)->isWorker($this->createStub(Project::class)));
    }

    private function callerRun(WorkerRunRepository $workerRuns): CallerRun
    {
        return new CallerRun($this->tokens, $this->requests, $workerRuns);
    }

    private function credential(AuthenticatedCredential $credential): void
    {
        $token = new PostAuthenticationToken(new InMemoryUser('worker', null, $credential->roles), 'api', $credential->roles);
        $token->setAttribute(AuthenticatedCredential::ATTRIBUTE, $credential);
        $this->tokens->setToken($token);
    }

    private function push(?string $session): void
    {
        $request = new Request();
        if (null !== $session) {
            $request->headers->set(CallerRun::SESSION_HEADER, $session);
        }
        $this->requests->push($request);
    }
}
