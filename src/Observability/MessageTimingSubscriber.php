<?php

declare(strict_types=1);

namespace App\Observability;

use App\Messenger\Stamp\CauseStamp;
use App\Messenger\Stamp\DispatchedAtStamp;
use Psr\Clock\ClockInterface;
use Sentry\Unit;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/** Measures how long each message waited for a worker and how long its handlers ran, and sends the metrics while a worker runs. */
final class MessageTimingSubscriber implements EventSubscriberInterface
{
    private const string SCHEDULER_TRANSPORT = 'scheduler_default';
    private const int FLUSH_SECONDS = 30;

    private ?float $startedAt = null;

    private ?object $message = null;

    private ?float $flushedAt = null;

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageReceivedEvent::class => ['onReceived', 30],
            WorkerMessageHandledEvent::class => ['onHandled', 70],
            WorkerMessageFailedEvent::class => ['onFailed', 70],
            WorkerRunningEvent::class => 'onRunning',
        ];
    }

    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        $this->startedAt = null;
        $this->message = null;
        if (self::SCHEDULER_TRANSPORT === $event->getReceiverName()) {
            return;
        }

        $now = $this->now();
        $envelope = $event->getEnvelope();
        $this->startedAt = $now;
        $this->message = $envelope->getMessage();

        $stamp = $envelope->last(DispatchedAtStamp::class);
        if ($stamp instanceof DispatchedAtStamp && null === $envelope->last(RedeliveryStamp::class)) {
            $wait = max(0.0, $now - (float) $stamp->dueAt->format('U.u'));
            \Sentry\traceMetrics()->distribution('messenger.message.wait', $wait, $this->attributes($event->getReceiverName(), $envelope), Unit::second());
        }
    }

    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        $this->finish($event->getReceiverName(), $event->getEnvelope(), 'ok');
    }

    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        $this->finish($event->getReceiverName(), $event->getEnvelope(), 'failed');
    }

    public function onRunning(WorkerRunningEvent $event): void
    {
        $now = $this->now();
        $this->flushedAt ??= $now;
        if ($now - $this->flushedAt < self::FLUSH_SECONDS) {
            return;
        }

        $this->flushedAt = $now;
        \Sentry\traceMetrics()->flush();
    }

    private function finish(string $transport, Envelope $envelope, string $status): void
    {
        if (null === $this->startedAt || $envelope->getMessage() !== $this->message) {
            return;
        }

        $seconds = max(0.0, $this->now() - $this->startedAt);
        $this->startedAt = null;
        $this->message = null;
        \Sentry\traceMetrics()->distribution('messenger.message.duration', $seconds, $this->attributes($transport, $envelope) + ['status' => $status], Unit::second());
    }

    /** @return array<string, string> */
    private function attributes(string $transport, Envelope $envelope): array
    {
        $attributes = ['transport' => $transport, 'message' => new \ReflectionClass($envelope->getMessage())->getShortName()];
        $cause = $envelope->last(CauseStamp::class);
        if ($cause instanceof CauseStamp) {
            $attributes['cause'] = $cause->cause;
        }

        return $attributes;
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
