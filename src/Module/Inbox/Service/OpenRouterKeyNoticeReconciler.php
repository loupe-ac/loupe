<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCloseCause;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxOpenRouterKeyNotice;
use App\Module\Inbox\Messenger\NotifyInboxOwner;
use App\Module\Inbox\Messenger\NotifyInboxOwnerKind;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Repository\InboxOpenRouterKeyNoticeRepository;
use App\Module\OpenRouter\Entity\OpenRouterKeyState;
use App\Module\OpenRouter\Repository\OpenRouterKeyRepository;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Keeps one open notice for each key and project while OpenRouter refuses the key.
 * An account key refused in several projects gets one notice in each. Every method
 * is safe to run twice.
 */
final readonly class OpenRouterKeyNoticeReconciler
{
    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projects,
        private InboxOpenRouterKeyNoticeRepository $inboxOpenRouterKeyNotices,
        private InboxItemRepository $inboxItems,
        private OpenRouterKeyRepository $openRouterKeys,
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

    public function refused(Uuid $keyId, bool $forProject, Uuid $projectId): void
    {
        $project = $this->projects->find($projectId);
        if (null === $project || !$this->inbox->isEnabled()) {
            return;
        }

        $notice = $this->em->wrapInTransaction(function () use ($keyId, $forProject, $project): ?InboxOpenRouterKeyNotice {
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);
            // A stale refusal must not outlive the key or a later read that worked.
            // The share lock makes a delete or a state change wait for this notice.
            if (OpenRouterKeyState::Refused !== $this->openRouterKeys->lockState($keyId)) {
                return null;
            }

            $now = self::whole($this->clock->now());
            $existing = $this->inboxOpenRouterKeyNotices->findOpenForKeyInProject($keyId, $project);
            if (null !== $existing) {
                $this->inboxItems->reloadChangeableColumns([$existing->item]);
            }
            if (null !== $existing && InboxItemState::Open === $existing->item->state) {
                return null;
            }
            // Doctrine inserts before it updates, so a notice whose item closed
            // elsewhere must end in the database before a new one is inserted.
            if (null !== $existing) {
                $existing->endedAt = $now;
                $this->em->flush();
            }

            $notice = $this->open($project, $keyId, $forProject, $now);
            $this->em->flush();
            $this->searchIndexer->index($notice->item);

            return $notice;
        });

        if (null !== $notice) {
            $this->inboxChanged->changed($project);
        }
    }

    /** OpenRouter accepts the key again. */
    public function works(Uuid $keyId): void
    {
        $this->closeAll($keyId, InboxItemState::Resolved);
    }

    /** The key is gone, so the refusal is no longer a fact about the project. */
    public function removed(Uuid $keyId): void
    {
        $this->closeAll($keyId, InboxItemState::Cancelled);
    }

    private function closeAll(Uuid $keyId, InboxItemState $state): void
    {
        foreach ($this->inboxOpenRouterKeyNotices->findOpenForKey($keyId) as $notice) {
            $project = $notice->project;
            $closed = $this->em->wrapInTransaction(function () use ($notice, $project, $state): bool {
                $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);
                $this->inboxOpenRouterKeyNotices->reloadEndedAt($notice);
                $this->inboxItems->reloadChangeableColumns([$notice->item]);
                if (null !== $notice->endedAt) {
                    return false;
                }

                // A stale works signal leaves the notice of a key refused again open,
                // and never resolves the notice of a removed key.
                $keyState = $this->openRouterKeys->lockState($notice->keyId);
                if (InboxItemState::Resolved === $state && OpenRouterKeyState::Refused === $keyState) {
                    return false;
                }

                $now = self::whole($this->clock->now());
                $resolved = InboxItemState::Resolved === $state && $this->inbox->isEnabled() && null !== $keyState;
                $closed = $this->closer->close($notice->item, $resolved ? InboxItemState::Resolved : InboxItemState::Cancelled, InboxCloser::Loupe, InboxCloseCause::FactEnded, null, $now);
                $notice->endedAt = $now;
                $this->em->flush();

                return $closed;
            });

            if ($closed) {
                $this->inboxChanged->changed($project);
            } else {
                $this->inboxChanged->pageChanged($project);
            }
        }
    }

    private function open(Project $project, Uuid $keyId, bool $forProject, \DateTimeImmutable $now): InboxOpenRouterKeyNotice
    {
        $item = new InboxItem(
            project: $project,
            number: $this->inboxItems->nextNumber($project),
            kind: InboxItemKind::Notice,
            title: $this->translator->trans('inbox.openrouter_key.title', [], null, $this->locale),
            blocking: false,
            origin: InboxAskOrigin::Loupe,
            createdAt: $now,
            searchLanguage: $project->searchLanguage,
        );
        $item->body = $this->translator->trans($forProject ? 'inbox.openrouter_key.body.project' : 'inbox.openrouter_key.body.account', [], null, $this->locale);
        $notice = new InboxOpenRouterKeyNotice($project, $item, $keyId, $now);
        $this->em->persist($item);
        $this->em->persist($notice);
        // The Doctrine transport commits the message with the notice.
        $this->bus->dispatch(new NotifyInboxOwner(($item->id ?? throw new \LogicException('A persisted item has an id.'))->toRfc4122(), NotifyInboxOwnerKind::Notice));

        return $notice;
    }

    /** Whole seconds, like the stored columns. */
    private static function whole(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return $moment->setTime((int) $moment->format('G'), (int) $moment->format('i'), (int) $moment->format('s'));
    }
}
