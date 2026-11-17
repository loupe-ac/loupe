<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Entity\Card;
use App\Module\Board\Mcp\AgentRunCause;
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

final class AgentRunCauseTest extends KernelTestCase
{
    use BoardToolScenario;
    use CallsAsWorkerRun;

    private EntityManagerInterface $em;
    private AgentRunCause $cause;
    private Project $project;
    private Card $card;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $cause = self::getContainer()->get(AgentRunCause::class);
        self::assertInstanceOf(AgentRunCause::class, $cause);
        $this->cause = $cause;

        $this->project = $this->makeProject('agent-run-cause');
        $this->card = new Card($this->project, $this->column($this->project, 'backlog'), 'Ship it', '', 1);
        $this->em->persist($this->card);
        $this->em->flush();
    }

    public function test_no_request_names_no_run(): void
    {
        self::assertNull($this->cause->forCard($this->card));
    }

    public function test_a_request_without_the_header_names_no_run(): void
    {
        $this->pushRequest(null);

        self::assertNull($this->cause->forCard($this->card));
    }

    public function test_a_malformed_header_names_no_run(): void
    {
        $this->pushRequest('not-a-uuid');

        self::assertNull($this->cause->forCard($this->card));
    }

    public function test_a_header_alone_names_no_run_even_for_a_known_worker_session(): void
    {
        $run = $this->workerRun('implement', new \DateTimeImmutable('-1 hour'));
        $this->pushRequest((string) $run->sessionId);

        self::assertNull($this->cause->forCard($this->card));
    }

    public function test_a_credential_names_its_run(): void
    {
        $run = $this->workerRun('fix-round', new \DateTimeImmutable('-1 hour'));
        $this->callAsRun($run);

        $cause = $this->cause->forCard($this->card);

        self::assertNotNull($cause);
        self::assertSame(['type' => 'run', 'run' => (string) $run->id, 'kind' => 'fix-round'], $cause->detail());
    }

    public function test_a_credential_names_its_run_even_when_the_run_works_another_card(): void
    {
        $run = $this->workerRun('implement', new \DateTimeImmutable('-1 hour'), cardId: Uuid::v4());
        $this->callAsRun($run);

        self::assertSame(['type' => 'run', 'run' => (string) $run->id, 'kind' => 'implement'], $this->cause->forCard($this->card)?->detail());
    }

    public function test_a_credential_wins_over_a_conflicting_header(): void
    {
        $mine = $this->workerRun('implement', new \DateTimeImmutable('-2 hours'));
        $other = $this->workerRun('fix-round', new \DateTimeImmutable('-1 hour'));
        $this->callAsRun($mine);
        $this->pushRequest((string) $other->sessionId);

        self::assertSame(['type' => 'run', 'run' => (string) $mine->id, 'kind' => 'implement'], $this->cause->forCard($this->card)?->detail());
    }

    public function test_a_credential_with_an_unknown_run_key_names_no_run(): void
    {
        $this->workerRun('implement', new \DateTimeImmutable('-1 hour'));
        $this->callAsWorkerRun(Uuid::v4(), Uuid::v4(), Uuid::v4());

        self::assertNull($this->cause->forCard($this->card));
    }

    public function test_a_credential_of_another_bridge_names_no_run(): void
    {
        $run = $this->workerRun('implement', new \DateTimeImmutable('-1 hour'));
        $this->callAsWorkerRun(Uuid::v4(), $run->runKey ?? throw new \LogicException('The run has a key.'), Uuid::v4());

        self::assertNull($this->cause->forCard($this->card));
    }

    public function test_a_credential_for_a_run_of_another_project_names_no_run(): void
    {
        $run = $this->workerRun('implement', new \DateTimeImmutable('-1 hour'), $this->makeProject('agent-run-cause-other'));
        $this->callAsRun($run);

        self::assertNull($this->cause->forCard($this->card));
    }

    public function test_a_credential_for_an_interactive_run_names_no_run(): void
    {
        $run = $this->workerRun('product-design', new \DateTimeImmutable('-1 hour'), kind: WorkerRunKind::Interactive);
        $this->callAsRun($run);

        self::assertNull($this->cause->forCard($this->card));
    }

    public function test_a_credential_names_its_run_after_the_run_closed(): void
    {
        $run = $this->workerRun('implement', new \DateTimeImmutable('-1 hour'), state: WorkerRunState::Succeeded);
        $this->callAsRun($run);

        self::assertSame(['type' => 'run', 'run' => (string) $run->id, 'kind' => 'implement'], $this->cause->forCard($this->card)?->detail());
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
        string $rule,
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
            workKind: $rule,
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
