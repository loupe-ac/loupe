<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCloseCause;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxRepositoryMisfit;
use App\Module\Inbox\Messenger\NotifyInboxOwner;
use App\Module\Inbox\Messenger\NotifyInboxOwnerKind;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Repository\InboxRepositoryMisfitRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\BlockingRepository;
use App\Module\Workflow\Contract\WorkHold;
use App\Module\Workflow\Contract\WorkHolds;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Keeps one open notice per project while a repository blocks its new work, and makes the notice match the
 * current hold: it names each blocking repository with its gaps, and it closes when no repository blocks.
 * It reads the hold itself and never trusts an event, so a late or repeated trigger changes nothing.
 */
final readonly class RepositoryMisfitReconciler
{
    public function __construct(
        private EntityManagerInterface $em,
        private WorkHolds $workHolds,
        private InboxRepositoryMisfitRepository $inboxRepositoryMisfits,
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

    public function reconcile(Project $project): void
    {
        $outcome = $this->em->wrapInTransaction(function () use ($project): ?bool {
            // The lock of a summary write: a hold read below waits for it, and reads what it left.
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

            $now = self::whole($this->clock->now());
            $misfit = $this->inboxRepositoryMisfits->findOpenForProject($project);

            // Doctrine inserts before it updates, so a misfit whose item closed elsewhere must end before a new one is inserted.
            if (null !== $misfit && InboxItemState::Open !== $misfit->item->state) {
                $misfit->endedAt = $now;
                $misfit = null;
                $this->em->flush();
            }

            $hold = $this->inbox->isEnabled() ? $this->workHolds->heldProject($project->requireId()) : null;
            if (null === $hold) {
                if (null === $misfit) {
                    return null;
                }
                $this->close($misfit, $now);
                $this->em->flush();

                return true;
            }

            $repositories = self::rows($hold);
            if (null === $misfit) {
                $misfit = $this->open($project, $repositories, $now);
                $this->em->flush();
                $this->searchIndexer->index($misfit->item);

                return true;
            }

            if ($misfit->repositories === $repositories) {
                return null;
            }
            $this->rewrite($misfit, $repositories, $now);
            $this->em->flush();
            $this->searchIndexer->index($misfit->item);

            return false;
        });

        if (true === $outcome) {
            $this->inboxChanged->changed($project);
        } elseif (false === $outcome) {
            $this->inboxChanged->pageChanged($project);
        }
    }

    /**
     * @return list<array{id: string, path: string, gaps: int}> by path, so two reads of one hold compare equal
     */
    private static function rows(WorkHold $hold): array
    {
        $rows = array_map(static fn (BlockingRepository $repository): array => ['id' => $repository->id, 'path' => $repository->path, 'gaps' => $repository->blockingGaps], $hold->repositories);
        usort($rows, static fn (array $a, array $b): int => $a['path'] <=> $b['path']);

        return $rows;
    }

    /** @param list<array{id: string, path: string, gaps: int}> $repositories */
    private function open(Project $project, array $repositories, \DateTimeImmutable $now): InboxRepositoryMisfit
    {
        $item = new InboxItem(
            project: $project,
            number: $this->inboxItems->nextNumber($project),
            kind: InboxItemKind::Notice,
            title: $this->title($repositories),
            blocking: false,
            origin: InboxAskOrigin::Loupe,
            createdAt: $now,
            searchLanguage: $project->searchLanguage,
        );
        $item->body = $this->body($repositories);
        $misfit = new InboxRepositoryMisfit($project, $item, $now, $repositories);
        $this->em->persist($item);
        $this->em->persist($misfit);
        // The Doctrine transport commits the message with the notice.
        $this->bus->dispatch(new NotifyInboxOwner(($item->id ?? throw new \LogicException('A persisted item has an id.'))->toRfc4122(), NotifyInboxOwnerKind::Notice));

        return $misfit;
    }

    /** @param list<array{id: string, path: string, gaps: int}> $repositories */
    private function rewrite(InboxRepositoryMisfit $misfit, array $repositories, \DateTimeImmutable $now): void
    {
        $misfit->repositories = $repositories;
        $misfit->item->title = $this->title($repositories);
        $misfit->item->body = $this->body($repositories);
        $misfit->item->updatedAt = $now;
    }

    /** @param list<array{id: string, path: string, gaps: int}> $repositories */
    private function title(array $repositories): string
    {
        $paths = array_column($repositories, 'path');
        $title = 1 === \count($paths)
            ? $this->translator->trans('inbox.repository_misfit.title.one', ['%repository%' => $paths[0]], null, $this->locale)
            : $this->translator->trans('inbox.repository_misfit.title.many', ['%repositories%' => implode(', ', $paths)], null, $this->locale);

        return mb_strimwidth($title, 0, InboxItem::MAX_TITLE_LENGTH, '…');
    }

    /** @param list<array{id: string, path: string, gaps: int}> $repositories */
    private function body(array $repositories): string
    {
        $lines = array_map(
            fn (array $row): string => '- '.$this->translator->trans('inbox.repository_misfit.body.repository', ['%repository%' => $row['path'], '%count%' => $row['gaps']], null, $this->locale),
            $repositories,
        );

        return $this->translator->trans('inbox.repository_misfit.body.intro', ['%count%' => array_sum(array_column($repositories, 'gaps'))], null, $this->locale)
            ."\n\n".implode("\n", $lines)
            ."\n\n".$this->translator->trans('inbox.repository_misfit.body.remedy', [], null, $this->locale);
    }

    private function close(InboxRepositoryMisfit $misfit, \DateTimeImmutable $now): void
    {
        $this->closer->close(
            $misfit->item,
            InboxItemState::Resolved,
            InboxCloser::Loupe,
            InboxCloseCause::FactEnded,
            $this->translator->trans('inbox.repository_misfit.closed', [], null, $this->locale),
            $now,
        );
        $misfit->endedAt = $now;
    }

    /** Whole seconds, like the stored columns. */
    private static function whole(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return $moment->setTime((int) $moment->format('G'), (int) $moment->format('i'), (int) $moment->format('s'));
    }
}
