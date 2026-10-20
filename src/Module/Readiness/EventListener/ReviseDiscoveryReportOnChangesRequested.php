<?php

declare(strict_types=1);

namespace App\Module\Readiness\EventListener;

use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Event\ReviewSubmitted;
use App\Module\Workflow\Contract\CardEvaluations;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** The owner asked for changes to a discovery report. The run moves to Revising, and the app rule asks a worker to revise the same report. */
#[AsEventListener]
final readonly class ReviseDiscoveryReportOnChangesRequested
{
    public function __construct(
        private DiscoveryRunRepository $discoveryRuns,
        private CardEvaluations $evaluations,
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
        if (null === $run || DiscoveryRunState::Reported !== $run->state
            || $event->review->version->versionNumber !== $document->currentVersion()->versionNumber) {
            return;
        }

        $run->state = DiscoveryRunState::Revising;
        $this->em->flush();
        $this->logger->info('readiness.report_revision_requested', ['discoveryRunId' => (string) $run->id, 'projectId' => (string) $run->project->id, 'documentId' => (string) $document->id]);

        if ($this->evaluations->isOn()) {
            $this->evaluations->forCards([$run->card->id ?? throw new \LogicException('A stored card has an id.')]);
        }
    }
}
