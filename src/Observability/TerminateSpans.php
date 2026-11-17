<?php

declare(strict_types=1);

namespace App\Observability;

use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Names the two publishes that run after the response, so Sentry can sum them
 * per route. Each pair of listeners brackets one publisher by priority:
 * ImmediateOutboxPublisher runs at 16 and LiveUpdatePublisher at 8, both
 * above the transaction end at 5.
 */
final class TerminateSpans implements ResetInterface
{
    public const string OUTBOX_OP = 'outbox.drain';

    public const string LIVE_OP = 'live.publish';

    /** @var array<string, array{span: Span, parent: Span}> */
    private array $open = [];

    public function __construct(
        private readonly HubInterface $hub,
    ) {
    }

    #[AsEventListener(KernelEvents::TERMINATE, priority: 17)]
    public function startOutbox(): void
    {
        $this->start(self::OUTBOX_OP);
    }

    #[AsEventListener(KernelEvents::TERMINATE, priority: 15)]
    public function finishOutbox(): void
    {
        $this->finish(self::OUTBOX_OP);
    }

    #[AsEventListener(KernelEvents::TERMINATE, priority: 9)]
    public function startLive(): void
    {
        $this->start(self::LIVE_OP);
    }

    #[AsEventListener(KernelEvents::TERMINATE, priority: 7)]
    public function finishLive(): void
    {
        $this->finish(self::LIVE_OP);
    }

    #[\Override]
    public function reset(): void
    {
        $this->open = [];
    }

    private function start(string $op): void
    {
        $parent = $this->hub->getSpan();
        if (null === $parent) {
            return;
        }

        $span = $parent->startChild(SpanContext::make()->setOp($op));
        $this->hub->setSpan($span);
        $this->open[$op] = ['span' => $span, 'parent' => $parent];
    }

    private function finish(string $op): void
    {
        $open = $this->open[$op] ?? null;
        unset($this->open[$op]);
        if (null === $open) {
            return;
        }

        $open['span']->finish();
        $this->hub->setSpan($open['parent']);
    }
}
