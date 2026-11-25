<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Inbox\Messenger\OpenDiscoveryReview;
use App\Module\Readiness\Event\DiscoveryReportWaiting;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsEventListener]
final readonly class OpenInboxReviewOnDiscoveryReportWaiting
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(DiscoveryReportWaiting $event): void
    {
        // The Doctrine transport commits the message with the report, and the queue retries a failure.
        $this->bus->dispatch(new OpenDiscoveryReview((string) $event->projectId, (string) $event->runId, (string) $event->documentId));
    }
}
