<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Entity\CardSourceKind;
use App\Module\Board\Mcp\AgentRunSource;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Service\CallerRun;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\Support\CallsAsWorkerRun;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

final class AgentRunSourceTest extends KernelTestCase
{
    use BoardToolScenario;
    use CallsAsWorkerRun;

    private EntityManagerInterface $em;
    private AgentRunSource $source;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $source = self::getContainer()->get(AgentRunSource::class);
        self::assertInstanceOf(AgentRunSource::class, $source);
        $this->source = $source;

        $this->project = $this->makeProject('agent-run-source');
    }

    public function test_no_request_names_no_run(): void
    {
        self::assertNull($this->source->forProject($this->project));
    }

    public function test_a_request_without_the_header_names_no_run(): void
    {
        $this->pushRequest(null);

        self::assertNull($this->source->forProject($this->project));
    }

    public function test_a_malformed_header_names_no_run(): void
    {
        $this->pushRequest('not-a-uuid');

        self::assertNull($this->source->forProject($this->project));
    }

    public function test_a_header_alone_names_no_run_even_for_a_known_worker_session(): void
    {
        $run = $this->workerRun(new \DateTimeImmutable('-1 hour'));
        $this->pushRequest((string) $run->sessionId);

        self::assertNull($this->source->forProject($this->project));
    }

    public function test_a_credential_names_its_run_and_the_card_of_the_run(): void
    {
        $cardId = Uuid::v4();
        $run = $this->workerRun(new \DateTimeImmutable('-1 hour'), cardId: $cardId);
        $this->callAsRun($run);

        $source = $this->source->forProject($this->project);

        self::assertNotNull($source);
        self::assertSame(CardSourceKind::Run, $source->kind);
        self::assertEquals($run->id, $source->runId);
        self::assertEquals($cardId, $source->runCardId);
    }

    public function test_a_credential_wins_over_a_conflicting_header(): void
    {
        $mine = $this->workerRun(new \DateTimeImmutable('-2 hours'));
        $other = $this->workerRun(new \DateTimeImmutable('-1 hour'));
        $this->callAsRun($mine);
        $this->pushRequest((string) $other->sessionId);

        self::assertEquals($mine->id, $this->source->forProject($this->project)?->runId);
    }

    public function test_a_credential_with_an_unknown_run_key_names_no_run(): void
    {
        $this->workerRun(new \DateTimeImmutable('-1 hour'));
        $this->callAsWorkerRun(Uuid::v4(), Uuid::v4(), Uuid::v4());

        self::assertNull($this->source->forProject($this->project));
    }

    public function test_a_credential_of_another_bridge_names_no_run(): void
    {
        $run = $this->workerRun(new \DateTimeImmutable('-1 hour'));
        $this->callAsWorkerRun(Uuid::v4(), $run->runKey ?? throw new \LogicException('The run has a key.'), Uuid::v4());

        self::assertNull($this->source->forProject($this->project));
    }

    public function test_a_credential_for_a_run_of_another_project_names_no_run(): void
    {
        $run = $this->workerRun(new \DateTimeImmutable('-1 hour'), $this->makeProject('agent-run-source-other'));
        $this->callAsRun($run);

        self::assertNull($this->source->forProject($this->project));
    }

    public function test_a_credential_for_an_interactive_run_names_no_run(): void
    {
        $run = $this->workerRun(new \DateTimeImmutable('-1 hour'), kind: WorkerRunKind::Interactive);
        $this->callAsRun($run);

        self::assertNull($this->source->forProject($this->project));
    }

    public function test_a_credential_names_its_run_after_the_run_closed(): void
    {
        $run = $this->workerRun(new \DateTimeImmutable('-1 hour'), state: WorkerRunState::Succeeded);
        $this->callAsRun($run);

        self::assertEquals($run->id, $this->source->forProject($this->project)?->runId);
    }

    private function callAsRun(WorkerRun $run): void
    {
        $this->callAsWorkerRun(
            $run->bridgeId ?? throw new \LogicException('The run has a bridge.'),
            $run->runKey ?? throw new \LogicException('The run has a key.'),
            $run->sessionId ?? throw new \LogicException('The run has a session.'),
        );
    }

    private function pushRequest(?string $session): void
    {
        $request = Request::create('/mcp', Request::METHOD_POST);
        if (null !== $session) {
            $request->headers->set(CallerRun::SESSION_HEADER, $session);
        }

        $requests = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requests);
        $requests->push($request);
    }

    private function workerRun(
        \DateTimeImmutable $receivedAt,
        ?Project $project = null,
        ?Uuid $cardId = null,
        WorkerRunState $state = WorkerRunState::Succeeded,
        WorkerRunKind $kind = WorkerRunKind::Worker,
    ): WorkerRun {
        $run = new WorkerRun(
            project: $project ?? $this->project,
            bridgeId: Uuid::v4(),
            subjectType: WorkSubject::CARD,
            subjectId: $cardId ?? Uuid::v4(),
            cardNumber: 1,
            workKind: 'implement',
            state: $state,
            runKey: Uuid::v4(),
            sessionId: Uuid::v4(),
            receivedAt: $receivedAt,
            kind: $kind,
        );
        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }
}
