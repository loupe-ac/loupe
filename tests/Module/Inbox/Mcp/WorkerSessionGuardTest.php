<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Inbox\Mcp\WorkerSessionGuard;
use App\Module\Project\Entity\Project;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

final class WorkerSessionGuardTest extends TestCase
{
    private const string SESSION = '0192f0a4-7c1e-7d3b-9a52-3f1d2c4b5a69';

    private WorkerRunRepository&MockObject $workerRuns;
    private RequestStack $requests;
    private WorkerSessionGuard $guard;

    protected function setUp(): void
    {
        $this->workerRuns = $this->createMock(WorkerRunRepository::class);
        $this->requests = new RequestStack();
        $this->guard = new WorkerSessionGuard($this->requests, $this->workerRuns);
    }

    public function test_a_session_of_a_worker_run_is_refused(): void
    {
        $project = $this->createStub(Project::class);
        $this->callWith(self::SESSION);
        $this->workerRuns->expects($this->once())
            ->method('findNewestWorkerOfSession')
            ->with($project, Uuid::fromString(self::SESSION))
            ->willReturn($this->createStub(WorkerRun::class));

        try {
            $this->guard->requireNotWorker($project);
        } catch (ToolCallException $e) {
            self::assertSame('A bridge worker cannot answer an inbox item. Ask the owner with inbox_ask.', $e->getMessage());

            return;
        }

        self::fail('The guard let a worker session through.');
    }

    public function test_a_session_with_no_worker_run_passes(): void
    {
        $this->callWith(self::SESSION);
        $this->workerRuns->expects($this->once())->method('findNewestWorkerOfSession')->willReturn(null);

        $this->guard->requireNotWorker($this->createStub(Project::class));
    }

    public function test_a_call_with_no_header_passes(): void
    {
        $this->callWith(null);
        $this->workerRuns->expects($this->never())->method('findNewestWorkerOfSession');

        $this->guard->requireNotWorker($this->createStub(Project::class));
    }

    public function test_a_call_with_no_request_passes(): void
    {
        $this->workerRuns->expects($this->never())->method('findNewestWorkerOfSession');

        $this->guard->requireNotWorker($this->createStub(Project::class));
    }

    #[DataProvider('invalidSessions')]
    public function test_a_header_that_is_not_a_uuid_passes(string $value): void
    {
        $this->callWith($value);
        $this->workerRuns->expects($this->never())->method('findNewestWorkerOfSession');

        $this->guard->requireNotWorker($this->createStub(Project::class));
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
