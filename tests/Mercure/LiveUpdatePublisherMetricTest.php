<?php

declare(strict_types=1);

namespace App\Tests\Mercure;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\LiveUpdates;
use App\Mercure\LiveUpdateTransactionBuffer;
use App\Tests\Support\FeatureFlags;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Sentry\ClientBuilder;
use Sentry\Metrics\Types\Metric;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface as SentryHub;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

final class LiveUpdatePublisherMetricTest extends TestCase
{
    /** @var list<Metric> */
    private array $metrics = [];

    private SentryHub $previousHub;

    #[\Override]
    protected function setUp(): void
    {
        $this->previousHub = SentrySdk::getCurrentHub();
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
        SentrySdk::setCurrentHub($this->previousHub);
        parent::tearDown();
    }

    public function test_a_deduplicated_pair_counts_once_with_its_type_and_route(): void
    {
        $publisher = $this->publisher();
        $publisher->queue('https://loupe.test/board/1', ['type' => 'board.changed']);
        $publisher->queue('https://loupe.test/board/1', ['type' => 'board.changed']);

        $request = new Request();
        $request->attributes->set('_route', 'app_demo');
        $publisher->publish(new TerminateEvent($this->createStub(HttpKernelInterface::class), $request, new Response()));

        self::assertSame([['board.changed', 'app_demo', 1]], $this->published());
    }

    public function test_a_worker_names_the_message_class(): void
    {
        $publisher = $this->publisher();
        $publisher->queue('https://loupe.test/board/1', ['type' => 'a']);

        $publisher->publish(new WorkerMessageHandledEvent(new Envelope(new \ArrayObject()), 'async'));

        self::assertSame([['a', 'ArrayObject', 1]], $this->published());
    }

    public function test_a_console_run_names_the_command(): void
    {
        $publisher = $this->publisher();
        $publisher->queue('https://loupe.test/board/1', ['type' => 'a']);

        $publisher->publish(new ConsoleTerminateEvent(new Command('app:demo'), new ArrayInput([]), new NullOutput(), 0));

        self::assertSame([['a', 'app:demo', 1]], $this->published());
    }

    public function test_a_call_with_no_event_names_an_unknown_cause(): void
    {
        $publisher = $this->publisher();
        $publisher->queue('https://loupe.test/board/1', ['type' => 'a']);

        $publisher->publish();

        self::assertSame([['a', 'unknown', 1]], $this->published());
    }

    public function test_a_failed_hub_call_counts_nothing(): void
    {
        $publisher = new LiveUpdatePublisher(
            new RequestStack(),
            new LiveUpdateTransactionBuffer(),
            FeatureFlags::service([LiveUpdates::FLAG => true]),
            new Logger('test'),
            static fn (): HubInterface => new MockHub('http://mercure/.well-known/mercure', new StaticTokenProvider('token'), static fn (): string => throw new \RuntimeException('down')),
        );
        $publisher->queue('https://loupe.test/board/1', ['type' => 'a']);

        $publisher->publish();

        \Sentry\traceMetrics()->flush();
        self::assertSame([], $this->metrics);
    }

    /** @return list<array{string, string, int|float}> */
    private function published(): array
    {
        \Sentry\traceMetrics()->flush();
        $rows = [];
        foreach ($this->metrics as $metric) {
            if ('live.published' === $metric->getName()) {
                $attributes = $metric->getAttributes()->toSimpleArray();
                $rows[] = [(string) $attributes['type'], (string) $attributes['cause'], $metric->getValue()];
            }
        }

        return $rows;
    }

    private function publisher(): LiveUpdatePublisher
    {
        return new LiveUpdatePublisher(
            new RequestStack(),
            new LiveUpdateTransactionBuffer(),
            FeatureFlags::service([LiveUpdates::FLAG => true]),
            new Logger('test'),
            static fn (): HubInterface => new MockHub('http://mercure/.well-known/mercure', new StaticTokenProvider('token'), static fn (Update $update): string => 'id'),
        );
    }
}
