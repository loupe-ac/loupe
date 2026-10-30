<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Workflow\Install\WorkflowInstallFlags;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Module\Workflow\Repository\WorkflowQueuedEvaluationRepository;
use App\Module\Workflow\Service\EvaluationTrigger;
use App\Tests\Support\FeatureFlags;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Uid\Uuid;

final class EvaluationTriggerTest extends TestCase
{
    /** @var list<object> */
    private array $dispatched = [];

    /** @var list<object> */
    private array $stamps = [];

    public function test_it_queues_one_evaluation_per_unique_card(): void
    {
        $one = Uuid::v7();
        $two = Uuid::v7();

        $this->trigger(merge: false)->forCards([$one, $two->toRfc4122(), $one->toRfc4122(), $two, Uuid::fromString($one->toBase58())]);

        self::assertEquals([new EvaluateCard($one->toRfc4122()), new EvaluateCard($two->toRfc4122())], $this->dispatched);
    }

    public function test_with_the_flag_off_it_claims_nothing_and_queues_every_trigger(): void
    {
        $one = Uuid::v7();
        $queued = $this->createMock(WorkflowQueuedEvaluationRepository::class);
        $queued->expects($this->never())->method('claim');
        $trigger = $this->trigger(merge: false, queued: $queued);

        $trigger->forCards([$one]);
        $trigger->forCards([$one]);

        self::assertCount(2, $this->dispatched);
    }

    public function test_with_the_flag_on_it_queues_a_card_only_when_the_claim_succeeds(): void
    {
        $one = Uuid::v7();
        $queued = $this->createMock(WorkflowQueuedEvaluationRepository::class);
        $queued->expects($this->exactly(2))->method('claim')->willReturnOnConsecutiveCalls(true, false);
        $trigger = $this->trigger(merge: true, queued: $queued);

        $trigger->forCards([$one]);
        $trigger->forCards([$one]);

        self::assertEquals([new EvaluateCard($one->toRfc4122())], $this->dispatched);
    }

    public function test_it_queues_a_background_evaluation_on_the_low_priority_transport_without_a_claim(): void
    {
        $one = Uuid::v7();
        $queued = $this->createMock(WorkflowQueuedEvaluationRepository::class);
        $queued->expects($this->never())->method('claim');

        $this->trigger(merge: true, queued: $queued)->inBackground([$one, $one->toRfc4122()]);

        self::assertEquals([new EvaluateCard($one->toRfc4122())], $this->dispatched);
        self::assertEquals([new TransportNamesStamp(['card_sweep'])], $this->stamps);
    }

    public function test_it_is_always_on(): void
    {
        self::assertTrue($this->trigger(merge: false)->isOn());
    }

    private function trigger(bool $merge, ?WorkflowQueuedEvaluationRepository $queued = null): EvaluationTrigger
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message, array $stamps = []): Envelope {
            $this->dispatched[] = $message;
            array_push($this->stamps, ...$stamps);

            return new Envelope($message);
        });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        return new EvaluationTrigger(
            $bus,
            $em,
            $queued ?? $this->createStub(WorkflowQueuedEvaluationRepository::class),
            FeatureFlags::service([WorkflowInstallFlags::FLAG_MERGE_QUEUED_EVALUATIONS => $merge]),
            new MockClock('2026-10-30 12:00:00'),
        );
    }
}
