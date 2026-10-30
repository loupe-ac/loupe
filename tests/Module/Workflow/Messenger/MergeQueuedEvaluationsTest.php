<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Messenger;

use App\Module\Workflow\Command\EvaluateWorkflowCardHandler;
use App\Module\Workflow\Engine\Engine;
use App\Module\Workflow\Install\WorkflowInstallFlags;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Module\Workflow\Messenger\EvaluateCardHandler;
use App\Module\Workflow\Repository\WorkflowQueuedEvaluationRepository;
use App\Module\Workflow\Service\EvaluationTrigger;
use App\Module\Workflow\Service\WorkflowRowCleaner;
use App\Tests\Support\FeatureFlags;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class MergeQueuedEvaluationsTest extends KernelTestCase
{
    public function test_triggers_that_wait_together_queue_one_evaluation_and_a_trigger_after_the_receipt_queues_another(): void
    {
        $card = Uuid::v7();
        $trigger = $this->trigger(true);

        $trigger->forCards([$card]);
        $trigger->forCards([$card]);
        $trigger->forCards([$card]);
        self::assertCount(1, $this->transport()->getSent());

        $this->handler()(new EvaluateCard($card->toRfc4122()));
        $trigger->forCards([$card]);

        self::assertCount(2, $this->transport()->getSent());
    }

    public function test_a_trigger_while_an_evaluation_runs_queues_one_more_evaluation(): void
    {
        $card = Uuid::v7();
        $trigger = $this->trigger(true);
        $trigger->forCards([$card]);
        self::assertCount(1, $this->transport()->getSent());

        $handler = new EvaluateCardHandler(
            new EvaluateWorkflowCardHandler($this->service(Engine::class), $this->clockThatTriggers(static fn () => $trigger->forCards([$card]))),
            $this->service(WorkflowQueuedEvaluationRepository::class),
        );
        $handler(new EvaluateCard($card->toRfc4122()));

        self::assertCount(2, $this->transport()->getSent());
        self::assertSame(1, $this->rows($card));
        $trigger->forCards([$card]);
        self::assertCount(2, $this->transport()->getSent());
    }

    public function test_a_rolled_back_trigger_leaves_no_claim_for_the_next_one(): void
    {
        $card = Uuid::v7();
        $trigger = $this->trigger(true);
        $connection = $this->service(Connection::class);

        $connection->beginTransaction();
        $trigger->forCards([$card]);
        $connection->rollBack();
        $trigger->forCards([$card]);

        self::assertSame(1, $this->rows($card));
        self::assertCount(2, $this->transport()->getSent());
    }

    public function test_with_the_flag_off_every_trigger_queues_and_writes_no_row(): void
    {
        $card = Uuid::v7();
        $trigger = $this->trigger(false);

        $trigger->forCards([$card]);
        $trigger->forCards([$card]);

        self::assertCount(2, $this->transport()->getSent());
        self::assertSame(0, $this->rows($card));
    }

    public function test_the_handler_releases_the_row_with_the_flag_off_too(): void
    {
        $card = Uuid::v7();
        $this->trigger(true)->forCards([$card]);
        self::assertSame(1, $this->rows($card));

        $this->handler()(new EvaluateCard($card->toRfc4122()));

        self::assertSame(0, $this->rows($card));
    }

    public function test_forgetting_a_card_releases_its_row(): void
    {
        $card = Uuid::v7();
        $this->trigger(true)->forCards([$card]);

        $this->service(WorkflowRowCleaner::class)->forgetCard($card);

        self::assertSame(0, $this->rows($card));
    }

    private function trigger(bool $merge): EvaluationTrigger
    {
        self::bootKernel();
        $this->transport()->reset();

        return new EvaluationTrigger(
            $this->service(MessageBusInterface::class),
            $this->service(EntityManagerInterface::class),
            $this->service(WorkflowQueuedEvaluationRepository::class),
            FeatureFlags::service([WorkflowInstallFlags::FLAG_MERGE_QUEUED_EVALUATIONS => $merge]),
            new MockClock('2026-10-30 12:00:00'),
        );
    }

    private function handler(): EvaluateCardHandler
    {
        return new EvaluateCardHandler(
            new EvaluateWorkflowCardHandler($this->service(Engine::class), new MockClock('2026-10-30 12:00:00')),
            $this->service(WorkflowQueuedEvaluationRepository::class),
        );
    }

    private function rows(Uuid $card): int
    {
        return (int) $this->service(Connection::class)->fetchOne('SELECT COUNT(*) FROM workflow_queued_evaluations WHERE card_id = :card', ['card' => $card->toRfc4122()]);
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.card_eval');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    /** The evaluation reads the clock after the handler released the row and before the engine runs. */
    private function clockThatTriggers(\Closure $onNow): ClockInterface
    {
        return new readonly class($onNow) implements ClockInterface {
            public function __construct(
                private \Closure $onNow,
            ) {
            }

            public function now(): \DateTimeImmutable
            {
                ($this->onNow)();

                return new \DateTimeImmutable('2026-10-30 12:00:00');
            }
        };
    }
}
