<?php

declare(strict_types=1);

namespace App\Module\Readiness\EventListener;

use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Messenger\RequestDiscoveryRevision;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Event\ReviewSubmitted;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/** The owner asked for changes to a discovery report. The run moves to Revising, and a queued message asks a worker to revise the same report. */
#[AsEventListener]
final readonly class ReviseDiscoveryReportOnChangesRequested
{
    public function __construct(
        private DiscoveryRunRepository $discoveryRuns,
        private MessageBusInterface $bus,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReviewSubmitted $event): void
    {
        if (Verdict::ChangesRequested !== $event->review->verdict) {
            return;
        }
        $document = $event->review->version->document;
        $run = $this->discoveryRuns->findByReportDocument($document);
        if (null === $run || $event->review->version->versionNumber !== $document->currentVersion()->versionNumber) {
            return;
        }
        $fresh = $this->discoveryRuns->freshStateOf($run);
        if (DiscoveryRunState::Reported !== $fresh['state']) {
            return;
        }

        $run->state = DiscoveryRunState::Revising;
        $this->em->flush();
        $this->bus->dispatch(new RequestDiscoveryRevision((string) $run->id, $fresh['workRequestId']?->toRfc4122()));
        $this->logger->info('readiness.report_revision_requested', ['discoveryRunId' => (string) $run->id, 'projectId' => (string) $run->project->id, 'documentId' => (string) $document->id]);
    }
}
