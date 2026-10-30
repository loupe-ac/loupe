<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Messenger;

use App\Module\Board\Entity\Card;
use App\Module\Workflow\Command\EvaluateWorkflowCardHandler;
use App\Module\Workflow\Engine\Engine;
use App\Module\Workflow\Install\WorkflowInstallFlags;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Module\Workflow\Messenger\EvaluateCardHandler;
use App\Module\Workflow\Repository\WorkflowQueuedEvaluationRepository;
use App\Module\Workflow\Service\EvaluationTrigger;
use App\Module\Workflow\Service\WorkflowRowCleaner;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\FeatureFlags;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class MergeQueuedEvaluationsTest extends KernelTestCase
{
    use WorkflowProjects;

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
        $trigger = $this->trigger(true);
        $card = $this->storedCard();
        $trigger->forCards([$card]);
        self::assertCount(1, $this->transport()->getSent());

        $this->triggerWhenTheEngineLoads($card, $trigger);
        $this->handler()(new EvaluateCard($card->toRfc4122()));

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
            new EventDispatcher(),
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

    /** A stored card that the next read loads from the database, with no claim and no message left by its creation. */
    private function storedCard(): Uuid
    {
        $project = $this->workflowProject('merge-queued');
        $card = new Card($project, $this->column($project, 'next'), 'Card', '', 1);
        $this->em()->persist($card);
        $this->em()->flush();
        $id = $card->id ?? throw new \LogicException('The card is persisted.');
        $this->em()->clear();
        $this->service(WorkflowQueuedEvaluationRepository::class)->release($id);
        $this->transport()->reset();

        return $id;
    }

    /** The engine loads the card inside its pass, after the handler released the claim, so the first load sends the trigger there. */
    private function triggerWhenTheEngineLoads(Uuid $card, EvaluationTrigger $trigger): void
    {
        $listener = new class($card, $trigger) {
            private bool $fired = false;

            public function __construct(
                private readonly Uuid $card,
                private readonly EvaluationTrigger $trigger,
            ) {
            }

            public function postLoad(PostLoadEventArgs $args): void
            {
                $entity = $args->getObject();
                if ($this->fired || !$entity instanceof Card || null === $entity->id || !$entity->id->equals($this->card)) {
                    return;
                }
                $this->fired = true;
                $this->trigger->forCards([$this->card]);
            }
        };
        $this->em()->getEventManager()->addEventListener([Events::postLoad], $listener);
    }
}
