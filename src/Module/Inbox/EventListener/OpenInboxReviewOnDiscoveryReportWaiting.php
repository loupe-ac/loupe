<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Inbox\Service\DiscoveryReviewReconciler;
use App\Module\Readiness\Event\DiscoveryReportWaiting;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class OpenInboxReviewOnDiscoveryReportWaiting
{
    public function __construct(
        private DiscoveryReviewReconciler $reconciler,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DiscoveryReportWaiting $event): void
    {
        // The report is stored already. A failure here must not tell the worker that its report failed.
        try {
            $this->reconciler->waiting($event->projectId, $event->runId, $event->documentId);
        } catch (\Throwable $e) {
            $this->logger->error('inbox.discovery_review_open_failed', ['discoveryRunId' => (string) $event->runId, 'projectId' => (string) $event->projectId, 'exception' => $e]);
        }
    }
}
