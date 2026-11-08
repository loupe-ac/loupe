<?php

declare(strict_types=1);

namespace App\Tests\Messenger\Middleware;

use App\Messenger\Middleware\StampDispatchTimeMiddleware;
use App\Messenger\Stamp\CauseStamp;
use App\Messenger\Stamp\DispatchedAtStamp;
use App\Observability\HandledMessage;
use App\Observability\HandledMessageContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class StampDispatchTimeMiddlewareTest extends TestCase
{
    public function test_it_stamps_the_dispatch_time_as_the_due_time(): void
    {
        $now = new \DateTimeImmutable('2026-11-07 10:00:00');

        $stamp = $this->dispatch(new Envelope(new \stdClass()))->last(DispatchedAtStamp::class);

        self::assertEquals(new DispatchedAtStamp($now, $now), $stamp);
    }

    public function test_it_adds_the_delay_to_the_due_time(): void
    {
        $stamp = $this->dispatch(new Envelope(new \stdClass(), [new DelayStamp(30000)]))->last(DispatchedAtStamp::class);

        self::assertInstanceOf(DispatchedAtStamp::class, $stamp);
        self::assertEquals(new \DateTimeImmutable('2026-11-07 10:00:00'), $stamp->dispatchedAt);
        self::assertEquals(new \DateTimeImmutable('2026-11-07 10:00:30'), $stamp->dueAt);
    }

    public function test_it_keeps_an_existing_stamp_and_skips_a_received_message(): void
    {
        $old = new DispatchedAtStamp(new \DateTimeImmutable('2026-11-07 09:00:00'), new \DateTimeImmutable('2026-11-07 09:00:00'));

        self::assertSame($old, $this->dispatch(new Envelope(new \stdClass(), [$old]))->last(DispatchedAtStamp::class));
        self::assertNull($this->dispatch(new Envelope(new \stdClass(), [new ReceivedStamp('jobs')]))->last(DispatchedAtStamp::class));
    }

    public function test_it_holds_the_cause_while_a_received_message_is_handled_and_restores_it_after_a_throw(): void
    {
        $handled = new HandledMessage();
        $cause = new CauseStamp('EvaluateCardsOnCardMoved', new \DateTimeImmutable('2026-11-07 09:59:00'));
        $seen = new \ArrayObject();
        $inner = new readonly class($handled, $seen) implements MiddlewareInterface {
            /** @param \ArrayObject<int, mixed> $seen */
            public function __construct(
                private HandledMessage $handled,
                private \ArrayObject $seen,
            ) {
            }

            #[\Override]
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                $this->seen[] = $this->handled->current;

                throw new \RuntimeException('boom');
            }
        };
        $middleware = new StampDispatchTimeMiddleware(new MockClock('2026-11-07 10:00:00'), $handled);

        try {
            $middleware->handle(new Envelope(new \stdClass(), [new ReceivedStamp('card_eval'), $cause]), new StackMiddleware([$middleware, $inner]));
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertEquals(new HandledMessageContext($cause, 'card_eval'), $seen[0]);
        self::assertNull($handled->current);
    }

    private function dispatch(Envelope $envelope): Envelope
    {
        $middleware = new StampDispatchTimeMiddleware(new MockClock('2026-11-07 10:00:00'), new HandledMessage());
        $last = new class implements MiddlewareInterface {
            #[\Override]
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                return $envelope;
            }
        };

        return $middleware->handle($envelope, new StackMiddleware([$last]));
    }
}
