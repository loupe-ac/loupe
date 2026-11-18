<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Service;

use App\Module\AgentReview\Service\WithdrawnWorkGuard;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\CallerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Project\Entity\Project;
use App\Security\AuthenticatedCredential;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Uid\Uuid;

final class WithdrawnWorkGuardWorkerSessionTest extends TestCase
{
    private const string SESSION = '0192f0a4-7c1e-7d3b-9a52-3f1d2c4b5a69';

    private WorkerRunRepository&MockObject $workerRuns;
    private RequestStack $requests;
    private TokenStorage $tokens;
    private WithdrawnWorkGuard $guard;

    protected function setUp(): void
    {
        $this->workerRuns = $this->createMock(WorkerRunRepository::class);
        $this->requests = new RequestStack();
        $this->tokens = new TokenStorage();
        $this->guard = $this->guardOver($this->createStub(WorkRequestRepository::class));
    }

    public function test_a_session_of_a_worker_run_is_a_worker_call(): void
    {
        $project = $this->createStub(Project::class);
        $this->callWith(self::SESSION);
        $this->workerRuns->expects($this->once())
            ->method('findNewestWorkerOfSession')
            ->with($project, Uuid::fromString(self::SESSION))
            ->willReturn($this->createStub(WorkerRun::class));

        self::assertTrue($this->guard->currentCallIsWorker($project));
    }

    public function test_a_session_with_no_worker_run_is_not_a_worker_call(): void
    {
        $this->callWith(self::SESSION);
        $this->workerRuns->expects($this->once())->method('findNewestWorkerOfSession')->willReturn(null);

        self::assertFalse($this->guard->currentCallIsWorker($this->createStub(Project::class)));
    }

    public function test_a_call_with_no_header_is_not_a_worker_call(): void
    {
        $this->callWith(null);
        $this->workerRuns->expects($this->never())->method('findNewestWorkerOfSession');

        self::assertFalse($this->guard->currentCallIsWorker($this->createStub(Project::class)));
    }

    public function test_a_call_with_no_request_is_not_a_worker_call(): void
    {
        $this->workerRuns->expects($this->never())->method('findNewestWorkerOfSession');

        self::assertFalse($this->guard->currentCallIsWorker($this->createStub(Project::class)));
    }

    #[DataProvider('invalidSessions')]
    public function test_a_header_that_is_not_a_uuid_is_not_a_worker_call(string $value): void
    {
        $this->callWith($value);
        $this->workerRuns->expects($this->never())->method('findNewestWorkerOfSession');

        self::assertFalse($this->guard->currentCallIsWorker($this->createStub(Project::class)));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSessions(): iterable
    {
        yield 'a word' => ['not-a-uuid'];
        yield 'an empty value' => [''];
    }

    public function test_a_run_credential_is_a_worker_call_with_no_header_and_no_lookup(): void
    {
        $this->holdCredential();
        $this->workerRuns->expects($this->never())->method('findNewestWorkerOfSession');

        self::assertTrue($this->guard->currentCallIsWorker($this->createStub(Project::class)));
    }

    public function test_a_header_alone_is_never_withdrawn_even_when_its_session_has_a_withdrawn_run(): void
    {
        $this->callWith(self::SESSION);
        $this->workerRuns->expects($this->never())->method('findWorkerByRunKey');
        $workRequests = $this->createMock(WorkRequestRepository::class);
        $workRequests->expects($this->never())->method('find');
        $this->guard = $this->guardOver($workRequests);

        self::assertFalse($this->guard->currentCallIsWithdrawn($this->createStub(Project::class)));
    }

    #[DataProvider('withdrawnStates')]
    public function test_a_run_credential_is_withdrawn_when_the_work_request_of_its_run_is_withdrawn(WorkRequestState $state, bool $withdrawn): void
    {
        $project = $this->createStub(Project::class);
        $bridgeId = Uuid::v4();
        $runKey = Uuid::v4();
        $this->holdCredential($bridgeId, $runKey);
        $requestId = Uuid::v7();
        $run = new WorkerRun($project, $bridgeId, 'card', Uuid::v7(), 1, 'review', WorkerRunState::Running, $runKey, workRequestId: $requestId);
        $request = $this->createStub(WorkRequest::class);
        $request->state = $state;
        $this->workerRuns->expects($this->once())->method('findWorkerByRunKey')->with($project, $bridgeId, $runKey)->willReturn($run);
        $workRequests = $this->createMock(WorkRequestRepository::class);
        $workRequests->expects($this->once())->method('find')->with($requestId)->willReturn($request);
        $this->guard = $this->guardOver($workRequests);

        self::assertSame($withdrawn, $this->guard->currentCallIsWithdrawn($project));
    }

    /** @return iterable<string, array{WorkRequestState, bool}> */
    public static function withdrawnStates(): iterable
    {
        yield 'cancelled' => [WorkRequestState::Cancelled, true];
        yield 'expired' => [WorkRequestState::Expired, true];
        yield 'claimed' => [WorkRequestState::Claimed, false];
    }

    public function test_a_run_credential_wins_over_a_header_that_names_another_session(): void
    {
        $project = $this->createStub(Project::class);
        $bridgeId = Uuid::v4();
        $runKey = Uuid::v4();
        $this->holdCredential($bridgeId, $runKey);
        $this->callWith(self::SESSION);
        $this->workerRuns->expects($this->once())->method('findWorkerByRunKey')->with($project, $bridgeId, $runKey)->willReturn(null);
        $this->workerRuns->expects($this->never())->method('findNewestWorkerOfSession');

        self::assertFalse($this->guard->currentCallIsWithdrawn($project));
        self::assertTrue($this->guard->currentCallIsWorker($project));
    }

    private function guardOver(WorkRequestRepository $workRequests): WithdrawnWorkGuard
    {
        return new WithdrawnWorkGuard(new CallerRun($this->tokens, $this->requests, $this->workerRuns), $workRequests);
    }

    private function holdCredential(?Uuid $bridgeId = null, ?Uuid $runKey = null): void
    {
        $credential = new AuthenticatedCredential('worker-run', ['ROLE_API_MCP'], workerBridgeId: $bridgeId ?? Uuid::v4(), workerRunKey: $runKey ?? Uuid::v4(), workerSessionId: Uuid::v4());
        $token = new PostAuthenticationToken(new InMemoryUser('worker', null, ['ROLE_API_MCP']), 'api', ['ROLE_API_MCP']);
        $token->setAttribute(AuthenticatedCredential::ATTRIBUTE, $credential);
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
