<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Inbox\Messenger\OpenDiscoveryReview;
use App\Module\Readiness\Event\DiscoveryReportWaiting;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsEventListener]
final readonly class OpenInboxReviewOnDiscoveryReportWaiting
{
    public function __construct(
        private MessageBusInterface $bus,
        private DocumentRepository $documents,
        private DocumentVersionRepository $documentVersions,
    ) {
    }

    public function __invoke(DiscoveryReportWaiting $event): void
    {
        // The Doctrine transport commits the message with the report, and the queue retries a failure.
        // Read inside the report transaction, so each delivery of the message names the announced version.
        $document = $this->documents->find($event->documentId) ?? throw new \LogicException('A waiting report has a document.');
        $version = $this->documentVersions->findLatest($document)->versionNumber;
        $this->bus->dispatch(new OpenDiscoveryReview((string) $event->projectId, (string) $event->runId, (string) $event->documentId, $version));
    }
}
