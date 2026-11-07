<?php

declare(strict_types=1);

namespace App\Messenger\Middleware;

use App\Messenger\Stamp\DispatchedAtStamp;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/** Stamps the dispatch time and the due time of a message, so a worker can measure how long it waited. */
final readonly class StampDispatchTimeMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ClockInterface $clock,
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

        return $stack->next()->handle($envelope, $stack);
    }
}
