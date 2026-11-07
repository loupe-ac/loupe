<?php

declare(strict_types=1);

namespace App\Messenger\Middleware;

use App\Messenger\Stamp\CauseStamp;
use App\Messenger\Stamp\DispatchedAtStamp;
use App\Observability\HandledMessage;
use App\Observability\HandledMessageContext;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Stamps the dispatch time and the due time of a message, so a worker can measure how long it waited.
 * While the handlers of a received message run, it also holds that message for the code under them.
 */
final readonly class StampDispatchTimeMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ClockInterface $clock,
        private HandledMessage $handled,
    ) {
    }

    #[\Override]
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (null === $envelope->last(ReceivedStamp::class) && null === $envelope->last(DispatchedAtStamp::class)) {
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $delay = $envelope->last(DelayStamp::class);
            $due = $delay instanceof DelayStamp && $delay->getDelay() > 0 ? $now->modify(\sprintf('+%d milliseconds', $delay->getDelay())) : $now;
            $envelope = $envelope->with(new DispatchedAtStamp($now, $due));
        }

        $received = $envelope->last(ReceivedStamp::class);
        if (!$received instanceof ReceivedStamp) {
            return $stack->next()->handle($envelope, $stack);
        }

        $previous = $this->handled->current;
        $this->handled->current = new HandledMessageContext($envelope->last(CauseStamp::class), $received->getTransportName());
        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->handled->current = $previous;
        }
    }
}
