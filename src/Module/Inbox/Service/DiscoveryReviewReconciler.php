<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCloseCause;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemDocument;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Inbox\Messenger\NotifyInboxOwner;
use App\Module\Inbox\Messenger\NotifyInboxOwnerKind;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Keeps one open review item for each discovery report that waits for a verdict. A verdict closes the item,
 * as it closes any review item of a document. Every method is safe to run twice.
 */
final readonly class DiscoveryReviewReconciler
{
    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projects,
        private DocumentRepository $documents,
        private DiscoveryRunRepository $discoveryRuns,
        private InboxItemRepository $inboxItems,
        private InboxItemCloser $closer,
        private InboxSearchIndexer $searchIndexer,
        private InboxChangedPublisher $inboxChanged,
        private InboxAvailability $inbox,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
        private MessageBusInterface $bus,

        #[Autowire(param: 'kernel.default_locale')]
        private string $locale,
    ) {
    }

    public function waiting(Uuid $projectId, Uuid $runId, Uuid $documentId): void
    {
        $project = $this->projects->find($projectId);
        $document = $this->documents->find($documentId);
        if (null === $project || null === $document || !$this->inbox->isEnabled()) {
            return;
        }

        $opened = $this->em->wrapInTransaction(function () use ($project, $document, $runId): bool {
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);
            // The message runs after the report commits, so a verdict or a newer run can have ended the wait since.
            if (DiscoveryRunState::Reported !== $this->discoveryRuns->findStateOfReport($document)
                || null !== $this->inboxItems->findOpenForDiscoveryRun($project, $runId)) {
                return false;
            }
            $this->open($project, $document, $runId);

            return true;
        });

        if ($opened) {
            $this->inboxChanged->changed($project);
        }
    }

    /** The report of the run waits for no verdict any more. This also runs with the inbox off, so no item stays open. */
    public function withdrawn(Uuid $projectId, Uuid $runId): void
    {
        $project = $this->projects->find($projectId);
        if (null === $project) {
            return;
        }

        $closed = $this->em->wrapInTransaction(function () use ($project, $runId): bool {
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);
            $item = $this->inboxItems->findOpenForDiscoveryRun($project, $runId);
            if (null === $item) {
                return false;
            }
            $this->inboxItems->reloadChangeableColumns([$item]);

            return $this->closer->close($item, InboxItemState::Cancelled, InboxCloser::Loupe, InboxCloseCause::FactEnded, $this->translator->trans('inbox.discovery_report.replaced', [], null, $this->locale), $this->clock->now());
        });

        if ($closed) {
            $this->inboxChanged->changed($project);
        }
    }

    private function open(Project $project, Document $document, Uuid $runId): void
    {
        $now = $this->clock->now();
        $item = new InboxItem(
            project: $project,
            number: $this->inboxItems->nextNumber($project),
            kind: InboxItemKind::Review,
            title: $this->translator->trans('inbox.discovery_report.title', [], null, $this->locale),
            blocking: false,
            origin: InboxAskOrigin::Loupe,
            createdAt: $now,
            searchLanguage: $project->searchLanguage,
        );
        $item->discoveryRunId = $runId;
        $item->documents->add(new InboxItemDocument($item, $document, $now));
        $this->em->persist($item);
        $this->em->persist(new InboxReview($item, $document));
        // The Doctrine transport commits the message with the item.
        $this->bus->dispatch(new NotifyInboxOwner(($item->id ?? throw new \LogicException('A persisted item has an id.'))->toRfc4122(), NotifyInboxOwnerKind::Notice));
        $this->em->flush();
        $this->searchIndexer->index($item);
    }
}
