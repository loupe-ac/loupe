<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Inbox\Service\DiscoveryReviewReconciler;
use App\Module\Readiness\Event\DiscoveryReportWithdrawn;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class CancelInboxReviewOnDiscoveryReportWithdrawn
{
    public function __construct(
        private DiscoveryReviewReconciler $reconciler,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DiscoveryReportWithdrawn $event): void
    {
        // The new run started already. A failure here must not fail that start.
        try {
            $this->reconciler->withdrawn($event->projectId, $event->runId);
        } catch (\Throwable $e) {
            $this->logger->error('inbox.discovery_review_cancel_failed', ['discoveryRunId' => (string) $event->runId, 'projectId' => (string) $event->projectId, 'exception' => $e]);
        }
    }
}
