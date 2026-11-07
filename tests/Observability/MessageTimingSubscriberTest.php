<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Messenger\Stamp\CauseStamp;
use App\Messenger\Stamp\DispatchedAtStamp;
use App\Observability\MessageTimingSubscriber;
use PHPUnit\Framework\TestCase;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Metrics\Types\Metric;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Worker;

final class MessageTimingSubscriberTest extends TestCase
{
    private HubInterface $hub;

    private \stdClass $seen;

    private MockClock $clock;

    private MessageTimingSubscriber $subscriber;

    #[\Override]
    protected function setUp(): void
    {
        $this->hub = SentrySdk::getCurrentHub();
        $this->seen = new \stdClass();
        $this->seen->metrics = [];
        $seen = $this->seen;
        $client = ClientBuilder::create([
            'dsn' => 'https://key@o0.ingest.sentry.io/1',
            'default_integrations' => false,
            'before_send_metric' => static function (Metric $metric) use ($seen): null {
                $seen->metrics[] = $metric;

                return null;
            },
        ])->getClient();
        SentrySdk::setCurrentHub(new Hub($client));
        $this->clock = new MockClock('2026-11-07 10:00:10');
        $this->subscriber = new MessageTimingSubscriber($this->clock);
    }

    #[\Override]
    protected function tearDown(): void
    {
        SentrySdk::setCurrentHub($this->hub);
    }

    public function test_it_measures_the_wait_and_the_duration_of_a_stamped_message(): void
    {
        $due = new \DateTimeImmutable('2026-11-07 10:00:00');
        $envelope = new Envelope(new \stdClass(), [new DispatchedAtStamp($due, $due), new CauseStamp('EvaluateCardsOnCardMoved', $due)]);

        $this->subscriber->onReceived(new WorkerMessageReceivedEvent($envelope, 'card_eval'));
        $this->clock->sleep(2);
        $this->subscriber->onHandled(new WorkerMessageHandledEvent($envelope, 'card_eval'));

        $metrics = $this->flushed();
        self::assertSame(['messenger.message.wait', 'messenger.message.duration'], array_map(static fn (Metric $m): string => $m->getName(), $metrics));
        self::assertEquals(10.0, $metrics[0]->getValue());
        self::assertEquals(2.0, $metrics[1]->getValue());
        self::assertSame(['transport' => 'card_eval', 'message' => 'stdClass', 'cause' => 'EvaluateCardsOnCardMoved'], $this->own($metrics[0]));
        self::assertSame('ok', $this->own($metrics[1])['status']);
    }

    public function test_it_skips_the_wait_of_a_redelivered_message_and_marks_a_failure(): void
    {
        $due = new \DateTimeImmutable('2026-11-07 10:00:00');
        $envelope = new Envelope(new \stdClass(), [new DispatchedAtStamp($due, $due), new RedeliveryStamp(1)]);

        $this->subscriber->onReceived(new WorkerMessageReceivedEvent($envelope, 'jobs'));
        $this->subscriber->onFailed(new WorkerMessageFailedEvent($envelope, 'jobs', new \RuntimeException('x')));

        $metrics = $this->flushed();
        self::assertCount(1, $metrics);
        self::assertSame('messenger.message.duration', $metrics[0]->getName());
        self::assertSame('failed', $this->own($metrics[0])['status']);
        self::assertArrayNotHasKey('cause', $this->own($metrics[0]));
    }

    public function test_it_measures_nothing_for_the_scheduler(): void
    {
        $envelope = new Envelope(new \stdClass());

        $this->subscriber->onReceived(new WorkerMessageReceivedEvent($envelope, 'scheduler_default'));
        $this->subscriber->onHandled(new WorkerMessageHandledEvent($envelope, 'scheduler_default'));

        self::assertSame([], $this->flushed());
    }

    public function test_the_worker_flushes_the_metrics_every_thirty_seconds(): void
    {
        $sent = new \ArrayObject();
        $transport = new readonly class($sent) implements TransportInterface {
            /** @param \ArrayObject<int, Event> $sent */
            public function __construct(
                private \ArrayObject $sent,
            ) {
            }

            #[\Override]
            public function send(Event $event): Result
            {
                $this->sent[] = $event;

                return new Result(ResultStatus::success(), $event);
            }

            #[\Override]
            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success());
            }
        };
        SentrySdk::setCurrentHub(new Hub(ClientBuilder::create(['dsn' => 'https://key@o0.ingest.sentry.io/1', 'default_integrations' => false])->setTransport($transport)->getClient()));
        $running = new WorkerRunningEvent($this->createStub(Worker::class), false);
        $envelope = new Envelope(new \stdClass());
        $this->subscriber->onRunning($running);
        $this->subscriber->onReceived(new WorkerMessageReceivedEvent($envelope, 'jobs'));
        $this->subscriber->onHandled(new WorkerMessageHandledEvent($envelope, 'jobs'));

        $this->clock->sleep(29);
        $this->subscriber->onRunning($running);
        self::assertCount(0, $sent);

        $this->clock->sleep(1);
        $this->subscriber->onRunning($running);
        self::assertCount(1, $sent);
    }

    /** @return array<string, mixed> the attributes the code sets, without the ones the SDK adds */
    private function own(Metric $metric): array
    {
        return array_diff_key($metric->getAttributes()->toSimpleArray(), array_flip(['sentry.environment', 'server.address', 'sentry.sdk.name', 'sentry.sdk.version']));
    }

    /** @return list<Metric> */
    private function flushed(): array
    {
        \Sentry\traceMetrics()->flush();

        return $this->seen->metrics;
    }
}
