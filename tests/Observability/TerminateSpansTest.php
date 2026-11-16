<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Mercure\LiveUpdatePublisher;
use App\Observability\TerminateSpans;
use App\Outbox\ImmediateOutboxPublisher;
use Sentry\State\Hub;
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class TerminateSpansTest extends KernelTestCase
{
    public function test_each_publish_becomes_a_named_child_of_the_transaction(): void
    {
        $transaction = new Transaction(TransactionContext::make());
        $transaction->initSpanRecorder();
        $hub = new Hub(null, null);
        $hub->setSpan($transaction);
        $spans = new TerminateSpans($hub);

        $spans->startOutbox();
        self::assertNotSame($transaction, $hub->getSpan());
        $spans->finishOutbox();
        self::assertSame($transaction, $hub->getSpan());
        $spans->startLive();
        $spans->finishLive();

        $children = array_values(array_filter(
            $transaction->getSpanRecorder()?->getSpans() ?? [],
            static fn (Span $span): bool => $span !== $transaction,
        ));
        self::assertSame(['outbox.drain', 'live.publish'], array_map(static fn (Span $span): ?string => $span->getOp(), $children));
        self::assertNotNull($children[0]->getEndTimestamp());
        self::assertSame($transaction->getSpanId(), $children[1]->getParentSpanId());
    }

    public function test_without_a_transaction_nothing_starts(): void
    {
        $hub = new Hub(null, null);
        $spans = new TerminateSpans($hub);

        $spans->startOutbox();
        $spans->finishOutbox();

        self::assertNull($hub->getSpan());
    }

    public function test_the_publishers_run_in_the_trace_in_the_order_drain_then_live(): void
    {
        self::bootKernel();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $order = [];
        foreach ($dispatcher->getListeners(KernelEvents::TERMINATE) as $listener) {
            if (!\is_array($listener)) {
                continue;
            }
            $priority = $dispatcher->getListenerPriority(KernelEvents::TERMINATE, $listener);
            $name = $listener[0]::class.'::'.$listener[1];
            $order[$name] = $priority;
        }

        $drain = $order[ImmediateOutboxPublisher::class.'::publish'] ?? null;
        $live = $order[LiveUpdatePublisher::class.'::publish'] ?? null;
        self::assertNotNull($drain);
        self::assertNotNull($live);
        self::assertGreaterThan($live, $drain);
        // The Sentry transaction ends at priority 5, and tracing is off in the test environment.
        self::assertGreaterThan(5, $live);

        self::assertSame($drain + 1, $order[TerminateSpans::class.'::startOutbox']);
        self::assertSame($drain - 1, $order[TerminateSpans::class.'::finishOutbox']);
        self::assertSame($live + 1, $order[TerminateSpans::class.'::startLive']);
        self::assertSame($live - 1, $order[TerminateSpans::class.'::finishLive']);
    }
}
