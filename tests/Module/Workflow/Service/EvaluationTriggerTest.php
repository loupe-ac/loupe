<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Messenger\Stamp\CauseStamp;
use App\Module\Workflow\Install\WorkflowInstallFlags;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Module\Workflow\Repository\WorkflowQueuedEvaluationRepository;
use App\Module\Workflow\Service\EvaluationTrigger;
use App\Tests\Support\FeatureFlags;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Sentry\ClientBuilder;
use Sentry\Metrics\Types\Metric;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
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

    /** @var list<Metric> */
    private array $metrics = [];

    private HubInterface $hub;

    #[\Override]
    protected function setUp(): void
    {
        $this->hub = SentrySdk::getCurrentHub();
        $this->metrics = [];
        $client = ClientBuilder::create([
            'dsn' => 'https://key@o0.ingest.sentry.io/1',
            'default_integrations' => false,
            'before_send_metric' => function (Metric $metric): null {
                $this->metrics[] = $metric;

                return null;
            },
        ])->getClient();
        SentrySdk::setCurrentHub(new Hub($client));
    }

    #[\Override]
    protected function tearDown(): void
    {
        SentrySdk::setCurrentHub($this->hub);
        parent::tearDown();
    }

    public function test_it_queues_one_evaluation_per_unique_card(): void
    {
        $one = Uuid::v7();
        $two = Uuid::v7();

        $this->trigger(merge: false)->forCards([$one, $two->toRfc4122(), $one->toRfc4122(), $two, Uuid::fromString($one->toBase58())], 'test');

        self::assertEquals([new EvaluateCard($one->toRfc4122()), new EvaluateCard($two->toRfc4122())], $this->dispatched);
    }

    public function test_with_the_flag_off_it_claims_nothing_and_queues_every_trigger(): void
    {
        $one = Uuid::v7();
        $queued = $this->createMock(WorkflowQueuedEvaluationRepository::class);
        $queued->expects($this->never())->method('claim');
        $trigger = $this->trigger(merge: false, queued: $queued);

        $trigger->forCards([$one], 'test');
        $trigger->forCards([$one], 'test');

        self::assertCount(2, $this->dispatched);
    }

    public function test_with_the_flag_on_it_queues_a_card_only_when_the_claim_succeeds(): void
    {
        $one = Uuid::v7();
        $queued = $this->createMock(WorkflowQueuedEvaluationRepository::class);
        $queued->expects($this->exactly(2))->method('claim')->willReturnOnConsecutiveCalls(true, false);
        $trigger = $this->trigger(merge: true, queued: $queued);

        $trigger->forCards([$one], 'test');
        $trigger->forCards([$one], 'test');

        self::assertEquals([new EvaluateCard($one->toRfc4122())], $this->dispatched);
    }

    public function test_it_queues_a_background_evaluation_on_the_low_priority_transport_without_a_claim(): void
    {
        $one = Uuid::v7();
        $queued = $this->createMock(WorkflowQueuedEvaluationRepository::class);
        $queued->expects($this->never())->method('claim');

        $this->trigger(merge: true, queued: $queued)->inBackground([$one, $one->toRfc4122()], 'test');

        self::assertEquals([new EvaluateCard($one->toRfc4122())], $this->dispatched);
        self::assertEquals([new TransportNamesStamp(['card_sweep'])], $this->stampsOf(TransportNamesStamp::class));
        self::assertCount(1, $this->stampsOf(CauseStamp::class));
    }

    public function test_it_stamps_the_short_cause_and_the_event_time(): void
    {
        $at = new \DateTimeImmutable('2026-10-30 11:59:00');

        $this->trigger(merge: false)->forCards([Uuid::v7()], \App\Module\Board\EventListener\EvaluateCardsOnCardMoved::class, $at);
        $this->trigger(merge: false)->forPersonWaiting([Uuid::v7()], 'Answer');

        self::assertEquals(
            [new CauseStamp('EvaluateCardsOnCardMoved', $at), new CauseStamp('Answer', new \DateTimeImmutable('2026-10-30 12:00:00'))],
            $this->stampsOf(CauseStamp::class),
        );
        self::assertEquals([new TransportNamesStamp(['interactive'])], $this->stampsOf(TransportNamesStamp::class));
    }

    public function test_a_merged_card_gets_no_stamped_message(): void
    {
        $queued = $this->createStub(WorkflowQueuedEvaluationRepository::class);
        $queued->method('claim')->willReturnOnConsecutiveCalls(true, false);

        $this->trigger(merge: true, queued: $queued)->forCards([Uuid::v7(), Uuid::v7()], 'test');

        self::assertCount(1, $this->dispatched);
        self::assertCount(1, $this->stampsOf(CauseStamp::class));
        self::assertSame(
            [['queued', 1, 'test', 'card_eval'], ['merged', 1, 'test', 'card_eval']],
            $this->enqueued(),
        );
    }

    public function test_it_is_always_on(): void
    {
        self::assertTrue($this->trigger(merge: false)->isOn());
    }

    /** @return list<array{string, int|float, string, string}> */
    private function enqueued(): array
    {
        \Sentry\traceMetrics()->flush();
        $rows = [];
        foreach ($this->metrics as $metric) {
            if ('workflow.eval.enqueued' === $metric->getName()) {
                $attributes = $metric->getAttributes()->toSimpleArray();
                $rows[] = [(string) $attributes['outcome'], $metric->getValue(), (string) $attributes['cause'], (string) $attributes['transport']];
            }
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($b[0], $a[0]));

        return $rows;
    }

    /**
     * @param class-string $class
     *
     * @return list<object>
     */
    private function stampsOf(string $class): array
    {
        return array_values(array_filter($this->stamps, static fn (object $stamp): bool => $stamp instanceof $class));
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
