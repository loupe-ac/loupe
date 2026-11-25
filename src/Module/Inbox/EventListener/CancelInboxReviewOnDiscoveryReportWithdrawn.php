<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Inbox\Messenger\CancelDiscoveryReview;
use App\Module\Readiness\Event\DiscoveryReportWithdrawn;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsEventListener]
final readonly class CancelInboxReviewOnDiscoveryReportWithdrawn
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(DiscoveryReportWithdrawn $event): void
    {
        // The Doctrine transport commits the message with the newer run, and the queue retries a failure.
        $this->bus->dispatch(new CancelDiscoveryReview((string) $event->projectId, (string) $event->runId));
    }
}
