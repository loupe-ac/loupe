<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\View\WorkerRunAction;
use App\Module\Bridge\View\WorkerRunControls;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Inbox\View\ItemAction;
use App\Module\Inbox\View\SuggestedAction;
use App\Module\Workflow\Command\ReleaseWorkflowPauseCommand;
use App\Module\Workflow\Contract\CardPauses;

/** Computes the actions that fit an inbox item now, in menu order, and marks the default. */
final readonly class ItemActions
{
    public function __construct(
        private InboxCardWatchRepository $inboxCardWatches,
        private WorkerRunRepository $workerRuns,
        private WorkerRunControls $runControls,
        private CardPauses $pauses,
    ) {
    }

    /** @return list<ItemAction> */
    public function forItem(InboxItem $item): array
    {
        return $this->forItems([$item])[self::key($item)] ?? [];
    }

    /**
     * The kinds that fit a card now, in menu order: those of its open wait item, and those that fit any card.
     *
     * @return list<InboxActionKind>
     */
    public function forCard(?InboxItem $waitItem): array
    {
        $fitting = null === $waitItem ? [] : array_map(static fn (ItemAction $action): InboxActionKind => $action->kind, $this->forItem($waitItem));
        $fitting = [...$fitting, InboxActionKind::Open, InboxActionKind::StartSession];

        return array_values(array_filter(InboxActionKind::cases(), static fn (InboxActionKind $kind): bool => \in_array($kind, $fitting, true)));
    }

    /**
     * The newest open wait that makes the kind fit the item now, or null. The run handler reads the run or the pause from it.
     */
    public function waitFor(InboxItem $item, InboxActionKind $kind): ?InboxCardWait
    {
        $watch = $this->inboxCardWatches->findForItems([$item])[0] ?? null;
        $waits = null === $watch ? [] : self::openWaits($watch->waits->toArray());

        $match = match ($kind) {
            InboxActionKind::ResumeRun => $this->resumableRuns($this->runsOf($waits)),
            InboxActionKind::RetryCard => $this->releasablePauses($waits),
            default => [],
        };
        foreach (array_reverse($waits) as $wait) {
            $id = InboxActionKind::ResumeRun === $kind ? $wait->runId : $wait->pauseId;
            if (null !== $id && isset($match[$id->toRfc4122()]) && self::typeOf($kind) === $wait->type) {
                return $wait;
            }
        }

        return null;
    }

    private static function typeOf(InboxActionKind $kind): InboxCardWaitType
    {
        return InboxActionKind::ResumeRun === $kind ? InboxCardWaitType::WorkerRun : InboxCardWaitType::CardPause;
    }

    /**
     * One watch query, one run query and one control read per project. A pause is read once per card with an open pause wait.
     *
     * @param list<InboxItem> $items
     *
     * @return array<string, list<ItemAction>> keyed by the RFC 4122 item id
     */
    public function forItems(array $items): array
    {
        $openWaits = [];
        foreach ($this->inboxCardWatches->findForItems($items) as $watch) {
            $openWaits[self::key($watch->item)] = self::openWaits($watch->waits->toArray());
        }

        $runs = $this->runsOf(array_merge(...array_values($openWaits)));
        $resumableRuns = $this->resumableRuns($runs);
        $releasablePauses = $this->releasablePauses(array_merge(...array_values($openWaits)));

        $actions = [];
        foreach ($items as $item) {
            $waits = $openWaits[self::key($item)] ?? [];
            $fitting = $this->fitting($item, $waits, $resumableRuns, $releasablePauses);
            $suggestions = self::suggestions($waits, $runs);
            $default = self::defaultKind($item, $waits, $fitting, $suggestions);
            $actions[self::key($item)] = array_map(
                static fn (InboxActionKind $kind): ItemAction => ItemAction::for($kind, $kind === $default, $suggestions[$kind->value]->reason ?? null),
                $fitting,
            );
        }

        return $actions;
    }

    /**
     * @param list<InboxCardWait> $waits            open waits, oldest first
     * @param array<string, true> $resumableRuns    run ids that offer Resume
     * @param array<string, true> $releasablePauses pause ids a person can release
     *
     * @return list<InboxActionKind>
     */
    private function fitting(InboxItem $item, array $waits, array $resumableRuns, array $releasablePauses): array
    {
        $fits = [
            [InboxActionKind::ResumeRun, self::fitsResumeRun($waits, $resumableRuns)],
            [InboxActionKind::RetryCard, self::fitsRetryCard($waits, $releasablePauses)],
            [InboxActionKind::Review, self::fitsReview($waits)],
            [InboxActionKind::Open, self::fitsOpen($item)],
            [InboxActionKind::Dismiss, self::fitsDismiss($item)],
        ];

        $fitting = [];
        foreach ($fits as [$kind, $fit]) {
            if ($fit) {
                $fitting[] = $kind;
            }
        }

        return $fitting;
    }

    /**
     * @param list<InboxCardWait> $waits
     * @param array<string, true> $resumableRuns
     */
    private static function fitsResumeRun(array $waits, array $resumableRuns): bool
    {
        return array_any($waits, fn ($wait) => InboxCardWaitType::WorkerRun === $wait->type && null !== $wait->runId && isset($resumableRuns[$wait->runId->toRfc4122()]));
    }

    /**
     * @param list<InboxCardWait> $waits
     * @param array<string, true> $releasablePauses
     */
    private static function fitsRetryCard(array $waits, array $releasablePauses): bool
    {
        return array_any($waits, fn ($wait) => null !== $wait->pauseId && isset($releasablePauses[$wait->pauseId->toRfc4122()]));
    }

    /** @param list<InboxCardWait> $waits */
    private static function fitsReview(array $waits): bool
    {
        return array_any($waits, fn ($wait) => \in_array($wait->type, [InboxCardWaitType::Document, InboxCardWaitType::PullRequest], true));
    }

    private static function fitsOpen(InboxItem $item): bool
    {
        return InboxItemKind::Wait === $item->kind || !$item->cards->isEmpty() || !$item->documents->isEmpty();
    }

    private static function fitsDismiss(InboxItem $item): bool
    {
        return InboxItemKind::Wait === $item->kind;
    }

    /**
     * The suggestions of the worker whose run wait is the newest open one, keyed by action.
     *
     * @param list<InboxCardWait>      $waits open waits, oldest first
     * @param array<string, WorkerRun> $runs
     *
     * @return array<string, SuggestedAction>
     */
    private static function suggestions(array $waits, array $runs): array
    {
        $runWaits = array_filter($waits, static fn (InboxCardWait $wait): bool => InboxCardWaitType::WorkerRun === $wait->type && null !== $wait->runId);
        $newest = end($runWaits);
        $run = false === $newest || null === $newest->runId ? null : ($runs[$newest->runId->toRfc4122()] ?? null);
        if (null === $run) {
            return [];
        }

        $suggestions = [];
        foreach (RunSuggestions::actions($run) as $suggestion) {
            $suggestions[$suggestion->action] = $suggestion;
        }

        return $suggestions;
    }

    /**
     * The recommended suggestion when it fits. Otherwise the kind of the newest open wait when it fits,
     * otherwise Open, so a wait item always has a default that fits.
     *
     * @param list<InboxCardWait>            $waits       open waits, oldest first
     * @param list<InboxActionKind>          $fitting
     * @param array<string, SuggestedAction> $suggestions
     */
    private static function defaultKind(InboxItem $item, array $waits, array $fitting, array $suggestions): ?InboxActionKind
    {
        if (InboxItemKind::Wait !== $item->kind) {
            return null;
        }

        foreach ($fitting as $kind) {
            if (InboxActionKind::Dismiss !== $kind && true === ($suggestions[$kind->value]->recommended ?? false)) {
                return $kind;
            }
        }

        $newest = end($waits);
        $preferred = false === $newest ? InboxActionKind::Open : match ($newest->type) {
            InboxCardWaitType::WorkerRun => InboxActionKind::ResumeRun,
            InboxCardWaitType::CardPause => InboxActionKind::RetryCard,
            InboxCardWaitType::Document, InboxCardWaitType::PullRequest => InboxActionKind::Review,
        };

        return \in_array($preferred, $fitting, true) ? $preferred : InboxActionKind::Open;
    }

    /**
     * The open waits, oldest first, with the order of CardWaitReconciler::rewrite(), so the last one is the newest.
     *
     * @param array<int, InboxCardWait> $waits
     *
     * @return list<InboxCardWait>
     */
    private static function openWaits(array $waits): array
    {
        $open = array_values(array_filter($waits, static fn (InboxCardWait $wait): bool => null === $wait->endedAt));
        usort($open, static fn (InboxCardWait $a, InboxCardWait $b): int => [$a->startedAt, $a->key()] <=> [$b->startedAt, $b->key()]);

        return $open;
    }

    /**
     * @param list<InboxCardWait> $waits
     *
     * @return array<string, WorkerRun> the runs of the run waits, keyed by the RFC 4122 run id
     */
    private function runsOf(array $waits): array
    {
        $runIds = [];
        foreach ($waits as $wait) {
            if (InboxCardWaitType::WorkerRun === $wait->type && null !== $wait->runId) {
                $runIds[$wait->runId->toRfc4122()] = $wait->runId->toRfc4122();
            }
        }

        $runs = [];
        foreach ($this->workerRuns->findByIds(array_values($runIds)) as $run) {
            if (null !== $run->id) {
                $runs[$run->id->toRfc4122()] = $run;
            }
        }

        return $runs;
    }

    /**
     * @param array<string, WorkerRun> $runs
     *
     * @return array<string, true> the ids of the runs whose control offers Resume with no reason against it
     */
    private function resumableRuns(array $runs): array
    {
        /** @var array<string, list<WorkerRun>> $byProject */
        $byProject = [];
        foreach ($runs as $run) {
            $byProject[(string) $run->project->id][] = $run;
        }

        $resumable = [];
        foreach ($byProject as $runs) {
            foreach ($this->runControls->forRuns($runs[0]->project, $runs) as $runId => $control) {
                if (WorkerRunAction::Resume === $control->action && null === $control->disabledReason) {
                    $resumable[$runId] = true;
                }
            }
        }

        return $resumable;
    }

    /**
     * @param list<InboxCardWait> $waits
     *
     * @return array<string, true> the ids of the active pauses of a kind that a person can release
     */
    private function releasablePauses(array $waits): array
    {
        $cards = [];
        foreach ($waits as $wait) {
            if (InboxCardWaitType::CardPause === $wait->type && null !== $wait->pauseId) {
                $cards[$wait->watch->cardId->toRfc4122()] = $wait->watch->cardId;
            }
        }

        $releasable = [];
        foreach ($cards as $cardId) {
            $pause = $this->pauses->findActive($cardId);
            if (null !== $pause && \in_array($pause->kind, ReleaseWorkflowPauseCommand::RELEASABLE_KINDS, true)) {
                $releasable[$pause->id->toRfc4122()] = true;
            }
        }

        return $releasable;
    }

    private static function key(InboxItem $item): string
    {
        return ($item->id ?? throw new \LogicException('The inbox item has no id.'))->toRfc4122();
    }
}
