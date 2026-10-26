<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Service;

use App\Module\AgentReview\Service\WithdrawnWorkGuard;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Project\Entity\Project;
use App\Module\Review\Service\WorkerSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

final class WithdrawnWorkGuardWorkerSessionTest extends TestCase
{
    private const string SESSION = '0192f0a4-7c1e-7d3b-9a52-3f1d2c4b5a69';

    private WorkerRunRepository&MockObject $workerRuns;
    private RequestStack $requests;
    private WorkerSession $guard;

    protected function setUp(): void
    {
        $this->workerRuns = $this->createMock(WorkerRunRepository::class);
        $this->requests = new RequestStack();
        $this->guard = new WithdrawnWorkGuard($this->requests, $this->workerRuns, $this->createStub(WorkRequestRepository::class));
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

    private function callWith(?string $session): void
    {
        $request = new Request();
        if (null !== $session) {
            $request->headers->set('X-Loupe-Session', $session);
        }
        $this->requests->push($request);
    }
}
