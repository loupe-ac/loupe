<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Scheduler;

use App\Module\Bridge\Entity\CardHold;
use App\Module\Workflow\Command\SweepWorkflowCardsHandler;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Module\Workflow\Scheduler\SweepWorkflowCardsTask;
use App\Module\Workflow\Service\EvaluationTrigger;
use App\Tests\Module\Workflow\Action\ActionScenario;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\ScheduledTasks;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class SweepWorkflowCardsTaskTest extends KernelTestCase
{
    use ActionScenario;

    public function test_the_task_is_registered_on_the_default_schedule_every_ten_minutes(): void
    {
        self::bootKernel();

        self::assertSame('*/10 * * * *', ScheduledTasks::cronExpressions(self::getContainer())[SweepWorkflowCardsTask::class] ?? null);
    }

    public function test_the_sweep_queues_the_open_cards_of_bound_projects_held_ones_too_and_logs_the_count(): void
    {
        self::bootKernel();
        $bound = $this->workflowProject('sweep-bound');
        $this->bindLifecycle($bound);
        $open = $this->card($bound, 'next');
        $backlog = $this->card($bound, 'backlog');
        $finished = $this->card($bound, 'done');
        $held = $this->card($bound, 'next');
        $this->em()->persist(new CardHold($bound, $held->id ?? throw new \LogicException('A flushed card has an id.'), null, new \DateTimeImmutable()));
        $this->em()->flush();
        $unbound = $this->card($this->workflowProject('sweep-unbound'), 'next');
        $this->transport()->reset();
        $logger = new RecordingLogger();

        $this->task($logger)();

        $sent = $this->sent();
        self::assertContains((string) $open->id, $sent);
        self::assertContains((string) $backlog->id, $sent);
        self::assertNotContains((string) $finished->id, $sent);
        self::assertContains((string) $held->id, $sent);
        self::assertNotContains((string) $unbound->id, $sent);
        self::assertCount(1, $logger->records);
        self::assertSame('workflow.sweep_finished', $logger->records[0]['message']);
        self::assertSame(\count($sent), $logger->records[0]['context']['cards']);
        self::assertIsInt($logger->records[0]['context']['durationMs']);
    }

    public function test_the_sweep_queues_on_the_low_priority_transport_only(): void
    {
        self::bootKernel();
        $bound = $this->workflowProject('sweep-low');
        $this->bindLifecycle($bound);
        $this->card($bound, 'next');
        $jobs = self::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $jobs);
        $cardEval = self::getContainer()->get('messenger.transport.card_eval');
        self::assertInstanceOf(InMemoryTransport::class, $cardEval);
        $jobs->reset();
        $cardEval->reset();
        $this->transport()->reset();

        $this->task(new RecordingLogger())();

        self::assertNotSame([], $this->sent());
        self::assertSame([], $jobs->getSent());
        self::assertSame([], $cardEval->getSent());
    }

    public function test_the_sweep_queues_nothing_while_the_previous_sweep_still_waits(): void
    {
        self::bootKernel();
        $bound = $this->workflowProject('sweep-skip');
        $this->bindLifecycle($bound);
        $this->card($bound, 'next');
        $this->transport()->reset();
        $logger = new RecordingLogger();

        $this->task($logger, waiting: 3)();

        self::assertSame([], $this->sent());
        self::assertSame(0, $logger->records[0]['context']['cards']);
    }

    private function task(RecordingLogger $logger, ?int $waiting = null): SweepWorkflowCardsTask
    {
        $transport = null === $waiting ? $this->transport() : new readonly class($waiting) implements TransportInterface, MessageCountAwareInterface {
            public function __construct(
                private int $waiting,
            ) {
            }

            public function getMessageCount(): int
            {
                return $this->waiting;
            }

            public function get(): iterable
            {
                return [];
            }

            public function ack(Envelope $envelope): void
            {
            }

            public function reject(Envelope $envelope): void
            {
            }

            public function send(Envelope $envelope): Envelope
            {
                return $envelope;
            }
        };

        return new SweepWorkflowCardsTask(
            new SweepWorkflowCardsHandler(
                $this->service(WorkflowBindingRepository::class),
                $this->service(EvaluationTrigger::class),
                $transport,
            ),
            $logger,
        );
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.card_sweep');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /** @return list<string> */
    private function sent(): array
    {
        return array_values(array_map(
            static fn (EvaluateCard $message): string => $message->cardId,
            array_filter(array_map(static fn ($envelope): object => $envelope->getMessage(), $this->transport()->getSent()), static fn (object $message): bool => $message instanceof EvaluateCard),
        ));
    }
}
