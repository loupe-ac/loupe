<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\RequestBridgeCommandHandler;
use App\Module\Bridge\Command\ResumeWorkerRunsCommand;
use App\Module\Bridge\Command\ResumeWorkerRunsEntry;
use App\Module\Bridge\Command\ResumeWorkerRunsHandler;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Service\BridgeCommandRefusals;
use App\Module\Bridge\ValueObject\BridgeCommandCause;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use App\Security\ApiKeyActor;
use App\Tests\Module\Bridge\BridgeScenario;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ResumeWorkerRunsHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private Project $project;
    private Bridge $bridge;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'resume-batch-'.uniqid().'@example.com');
        $this->project = $this->project($em, $owner, 'Resume batch');
        $this->bridge = $this->commandBridgeFor($owner);
    }

    public function test_each_command_carries_the_cause_and_the_key(): void
    {
        $first = $this->runOn($this->bridge);
        $second = $this->runOn($this->bridge, WorkerRunState::GaveUp);
        $key = new ApiKeyActor(Uuid::v7(), 'ci key');

        $view = $this->handler()(new ResumeWorkerRunsCommand(
            $this->project->owner,
            BridgeCommandCause::ApiKey,
            [new ResumeWorkerRunsEntry('a', static fn (): WorkerRun => $first), new ResumeWorkerRunsEntry('b', static fn (): WorkerRun => $second)],
            $key,
        ));

        self::assertSame(['resumed', 'resumed'], array_column($view->results, 'outcome'));
        $commands = $this->commands();
        self::assertCount(2, $commands);
        foreach ($commands as $command) {
            self::assertSame(BridgeCommandCause::ApiKey, $command->cause);
            self::assertSame((string) $key->keyId, (string) $command->apiKeyId);
            self::assertSame('ci key', $command->apiKeyName);
        }
    }

    public function test_an_agent_cause_records_no_key(): void
    {
        $run = $this->runOn($this->bridge);

        $this->handler()(new ResumeWorkerRunsCommand($this->project->owner, BridgeCommandCause::Agent, [new ResumeWorkerRunsEntry('a', static fn (): WorkerRun => $run)]));

        $command = $this->commands()[0];
        self::assertSame(BridgeCommandCause::Agent, $command->cause);
        self::assertNull($command->apiKeyId);
    }

    public function test_a_missing_run_and_a_refused_run_are_rows_and_the_batch_goes_on(): void
    {
        $succeeded = $this->runOn($this->bridge, WorkerRunState::Succeeded);
        $good = $this->runOn($this->bridge);

        $view = $this->handler()(new ResumeWorkerRunsCommand($this->project->owner, BridgeCommandCause::ApiKey, [
            new ResumeWorkerRunsEntry('missing', static fn (): ?WorkerRun => null),
            new ResumeWorkerRunsEntry('done', static fn (): WorkerRun => $succeeded),
            new ResumeWorkerRunsEntry('good', static fn (): WorkerRun => $good),
        ]));

        self::assertSame('not-found', $view->results[0]['code'] ?? null);
        self::assertSame('not-resumable', $view->results[1]['code'] ?? null);
        self::assertSame('resumed', $view->results[2]['outcome']);
    }

    public function test_an_unexpected_error_stops_the_batch_and_marks_the_rest_not_attempted(): void
    {
        $broken = $this->commandBridgeFor($this->project->owner);
        $before = $this->runOn($this->bridge);
        $failing = $this->runOn($broken);
        $after = $this->runOn($this->bridge, WorkerRunState::GaveUp);

        $real = self::getContainer()->get(BridgeRepository::class);
        self::assertInstanceOf(BridgeRepository::class, $real);
        $repository = $this->createStub(BridgeRepository::class);
        $repository->method('lockForWrite')->willReturnCallback(static function (string $ownerId, Uuid $bridgeId) use ($real, $broken): void {
            if ($bridgeId->equals($broken->id)) {
                throw new \RuntimeException('database went away');
            }
            $real->lockForWrite($ownerId, $bridgeId);
        });
        $repository->method('findOneByOwnerAndId')->willReturnCallback(static fn (User $owner, Uuid $id): ?Bridge => $real->findOneByOwnerAndId($owner, $id));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('bridge.resume_request_failed', self::anything());

        $request = $this->requestHandlerWith($repository);
        $handler = new ResumeWorkerRunsHandler($request, self::getContainer()->get(BridgeCommandRefusals::class), $logger);

        $view = $handler(new ResumeWorkerRunsCommand($this->project->owner, BridgeCommandCause::ApiKey, [
            new ResumeWorkerRunsEntry('before', static fn (): WorkerRun => $before),
            new ResumeWorkerRunsEntry('failing', static fn (): WorkerRun => $failing),
            new ResumeWorkerRunsEntry('after', static fn (): WorkerRun => $after),
        ]));

        self::assertSame('resumed', $view->results[0]['outcome']);
        self::assertSame('error', $view->results[1]['outcome']);
        self::assertSame(['runId' => 'after', 'outcome' => 'not-attempted'], $view->results[2]);
        self::assertCount(1, $this->commands());
    }

    public function test_a_failed_lookup_is_an_error_row_and_stops_the_batch(): void
    {
        $before = $this->runOn($this->bridge);
        $after = $this->runOn($this->bridge, WorkerRunState::GaveUp);

        $view = $this->handler()(new ResumeWorkerRunsCommand($this->project->owner, BridgeCommandCause::Agent, [
            new ResumeWorkerRunsEntry('before', static fn (): WorkerRun => $before),
            new ResumeWorkerRunsEntry('broken', static fn (): WorkerRun => throw new \RuntimeException('lookup failed')),
            new ResumeWorkerRunsEntry('after', static fn (): WorkerRun => $after),
        ]));

        self::assertSame(['resumed', 'error', 'not-attempted'], array_column($view->results, 'outcome'));
        self::assertCount(1, $this->commands());
    }

    private function handler(): ResumeWorkerRunsHandler
    {
        $handler = self::getContainer()->get(ResumeWorkerRunsHandler::class);
        self::assertInstanceOf(ResumeWorkerRunsHandler::class, $handler);

        return $handler;
    }

    private function requestHandlerWith(BridgeRepository $bridges): RequestBridgeCommandHandler
    {
        $c = self::getContainer();
        $real = $c->get(RequestBridgeCommandHandler::class);
        self::assertInstanceOf(RequestBridgeCommandHandler::class, $real);
        $copy = new \ReflectionClass($real);
        $args = [];
        foreach ($copy->getConstructor()?->getParameters() ?? [] as $parameter) {
            $args[$parameter->getName()] = $copy->getProperty($parameter->getName())->getValue($real);
        }
        $args['bridges'] = $bridges;

        return new RequestBridgeCommandHandler(...$args);
    }

    private function commandBridgeFor(User $owner): Bridge
    {
        $bridge = $this->seedBridge($this->em(), $owner, projects: [(string) $this->project->id]);
        $bridge->capabilities = [Bridge::CAPABILITY_COMMANDS];
        $this->em()->flush();

        return $bridge;
    }

    private function runOn(Bridge $bridge, WorkerRunState $state = WorkerRunState::Failed): WorkerRun
    {
        return $this->seedRun($this->em(), $this->project, exitCode: 1, bridgeId: $bridge->id, state: $state, runKey: Uuid::v7());
    }

    /** @return list<BridgeCommand> */
    private function commands(): array
    {
        $repository = self::getContainer()->get(BridgeCommandRepository::class);
        self::assertInstanceOf(BridgeCommandRepository::class, $repository);

        return array_values($repository->findAll());
    }
}
