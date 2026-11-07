<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Messenger\RefreshPullRequestState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class RefreshPullRequestsOnRunWaitingOnForgeTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private Project $project;
    private Card $card;
    private ForgePullRequest $row;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->project = $this->makeProject('run-waiting');
        $this->card = new Card($this->project, $this->column($this->project, 'backlog'), 'Fix it', '', 1);
        $this->em->persist($this->card);
        $this->em->persist(new CardPullRequest($this->card, 'https://github.com/Acme/Widgets/pull/7', Forge::GitHub, 'Acme/Widgets', 7));
        $this->row = new ForgePullRequest($this->project, 'github', 'acme/widgets', 7);
        $this->em->persist($this->row);
        $this->em->flush();
        $this->sentMessages();
    }

    public function test_a_run_waiting_on_the_forge_queues_an_immediate_and_a_delayed_read(): void
    {
        $run = $this->workerRun($this->card->id, WorkerRunState::WaitingOnForge);
        $this->clearQueue();

        $this->dispatch($this->event($run));

        $sent = $this->sentMessages();
        self::assertCount(2, $sent);
        $delays = [];
        foreach ($sent as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(RefreshPullRequestState::class, $message);
            self::assertSame((string) $this->row->id, $message->pullRequestId);
            $delays[] = $envelope->last(DelayStamp::class)?->getDelay();
        }
        self::assertEqualsCanonicalizing([null, 30_000], $delays);
    }

    public function test_a_run_in_another_state_queues_nothing(): void
    {
        $run = $this->workerRun($this->card->id, WorkerRunState::Succeeded);
        $this->clearQueue();

        $this->dispatch($this->event($run));

        self::assertSame([], $this->sentMessages());
    }

    public function test_a_run_waiting_on_the_forge_with_no_card_pull_request_queues_nothing(): void
    {
        $other = new Card($this->project, $this->column($this->project, 'backlog'), 'Other', '', 2);
        $this->em->persist($other);
        $this->em->flush();
        $run = $this->workerRun($other->id, WorkerRunState::WaitingOnForge);
        $this->clearQueue();

        $this->dispatch($this->event($run));

        self::assertSame([], $this->sentMessages());
    }

    private function dispatch(WorkerRunChanged $event): void
    {
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->dispatch($event);
    }

    private function workerRun(?Uuid $cardId, WorkerRunState $state): WorkerRun
    {
        $run = new WorkerRun(
            project: $this->project,
            bridgeId: Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: $cardId ?? throw new \LogicException('A persisted card has an id.'),
            cardNumber: 1,
            workKind: 'fix',
            state: $state,
        );
        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    private function event(WorkerRun $run): WorkerRunChanged
    {
        return new WorkerRunChanged(
            $this->project->id ?? throw new \LogicException('A persisted project has an id.'),
            [(string) $this->card->id],
            [(string) $run->id],
        );
    }

    /** @return list<Envelope> */
    private function sentMessages(): array
    {
        $transport = self::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_filter([...$transport->getSent()], static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof RefreshPullRequestState));
    }

    private function clearQueue(): void
    {
        $transport = self::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();
    }
}
