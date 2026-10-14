<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\View\WorkerRunAction;
use App\Module\Bridge\View\WorkerRunControls;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Inbox\View\ItemAction;
use App\Module\Inbox\View\SendAgentTarget;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Command\ReleaseWorkflowPauseCommand;
use App\Module\Workflow\Contract\CardPauses;
use App\Module\Workflow\Contract\StageWorks;
use Symfony\Component\Uid\Uuid;

/** Computes the actions that fit an inbox item now, in menu order, and marks the default. */
final readonly class ItemActions
{
    public function __construct(
        private InboxCardWatchRepository $inboxCardWatches,
        private WorkerRunRepository $workerRuns,
        private WorkerRunControls $runControls,
        private CardPauses $pauses,
        private SessionBridges $sessionBridges,
        private WorkRequestRepository $workRequests,
        private StageWorks $stageWorks,
    ) {
    }

    /** @return list<ItemAction> */
    public function forItem(InboxItem $item): array
    {
        return $this->forItems([$item])[self::key($item)] ?? [];
    }

    /**
     * The kinds that the owner can run on a card now, in menu order: the enabled actions of its open wait item,
     * or else an available Send an agent, Open and an available Start session.
     *
     * @return list<InboxActionKind>
     */
    public function forCard(Project $project, Uuid $cardId, ?InboxItem $waitItem): array
    {
        if (null !== $waitItem) {
            $enabled = array_filter($this->forItem($waitItem), static fn (ItemAction $action): bool => null === $action->disabledReasonKey);

            return array_values(array_map(static fn (ItemAction $action): InboxActionKind => $action->kind, $enabled));
        }

        $sessionFits = ($this->sessionBridges->onlineFor([$project])[(string) $project->id] ?? false)
            && !array_any($this->workRequests->findLiveRowsForCards([$cardId->toRfc4122()]), static fn (array $row): bool => WorkRequest::SESSION_KIND === $row['kind']);

        $fitting = [InboxActionKind::Open];
        if ([] !== $this->freeTargets(['card' => [$project, $cardId]])) {
            $fitting[] = InboxActionKind::SendAgent;
        }
        if ($sessionFits) {
            $fitting[] = InboxActionKind::StartSession;
        }

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
            InboxActionKind::ResumeRun => $this->resumableRuns($waits),
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

    /** The card and the stage work that Send an agent starts for the item now, or null when the action does not fit. */
    public function sendAgentFor(InboxItem $item): ?SendAgentTarget
    {
        $watch = $this->inboxCardWatches->findForItems([$item])[0] ?? null;

        return $this->sendAgentTargets([$item], null === $watch ? [] : [self::key($item) => $watch->cardId])[self::key($item)] ?? null;
    }

    private static function typeOf(InboxActionKind $kind): InboxCardWaitType
    {
        return InboxActionKind::ResumeRun === $kind ? InboxCardWaitType::WorkerRun : InboxCardWaitType::CardPause;
    }

    /** The card that an action on the item acts on: the card of its watch, or else its first linked card. */
    public function cardIdOf(InboxItem $item): ?Uuid
    {
        $watch = $this->inboxCardWatches->findForItems([$item])[0] ?? null;
        if (null !== $watch) {
            return $watch->cardId;
        }
        $link = $item->cards->first();

        return false === $link ? null : $link->card->id;
    }

    /**
     * One watch query, one run query and one control read per project. A pause is read once per card with an open pause wait.
     * One bridge read per project that has an item with a card, and one read of the live requests of their cards.
     *
     * @param list<InboxItem> $items
     *
     * @return array<string, list<ItemAction>> keyed by the RFC 4122 item id
     */
    public function forItems(array $items): array
    {
        $openWaits = [];
        $cardIds = [];
        $watchedCards = [];
        foreach ($this->inboxCardWatches->findForItems($items) as $watch) {
            $openWaits[self::key($watch->item)] = self::openWaits($watch->waits->toArray());
            $cardIds[self::key($watch->item)] = $watch->cardId->toRfc4122();
            $watchedCards[self::key($watch->item)] = $watch->cardId;
        }
        foreach ($items as $item) {
            $link = $item->cards->first();
            $linkedId = false === $link ? null : $link->card->id;
            if (!isset($cardIds[self::key($item)]) && null !== $linkedId) {
                $cardIds[self::key($item)] = $linkedId->toRfc4122();
            }
        }

        $sendAgent = $this->sendAgentTargets($items, $watchedCards);
        $resumableRuns = $this->resumableRuns(array_merge(...array_values($openWaits)));
        $releasablePauses = $this->releasablePauses(array_merge(...array_values($openWaits)));

        $withCard = array_filter($items, static fn (InboxItem $item): bool => isset($cardIds[self::key($item)]));
        $sessionOnline = $this->sessionBridges->onlineFor(array_values(array_map(static fn (InboxItem $item): Project => $item->project, $withCard)));
        $liveSessions = [];
        foreach ($this->workRequests->findLiveRowsForCards(array_values(array_unique($cardIds))) as $row) {
            if (WorkRequest::SESSION_KIND === $row['kind']) {
                $liveSessions[$row['card_id']] = true;
            }
        }

        $actions = [];
        foreach ($items as $item) {
            $waits = $openWaits[self::key($item)] ?? [];
            $cardId = $cardIds[self::key($item)] ?? null;
            $fitting = $this->fitting($item, $waits, $resumableRuns, $releasablePauses, isset($sendAgent[self::key($item)]), null !== $cardId && !isset($liveSessions[$cardId]));
            $default = self::defaultKind($item, $waits, $fitting);
            $actions[self::key($item)] = array_map(
                static fn (InboxActionKind $kind): ItemAction => ItemAction::for(
                    $kind,
                    $kind === $default,
                    InboxActionKind::StartSession === $kind && !($sessionOnline[(string) $item->project->id] ?? false) ? 'inbox.action.start-session.disabled' : null,
                ),
                $fitting,
            );
        }

        return $actions;
    }

    /**
     * @param list<InboxCardWait> $waits            open waits, oldest first
     * @param array<string, true> $resumableRuns    run ids that offer Resume
     * @param array<string, true> $releasablePauses pause ids a person can release
     * @param bool                $sendAgent        Send an agent has a card and a stage work that are free now
     * @param bool                $sessionFits      the item has a card, and no session request of that card is live
     *
     * @return list<InboxActionKind>
     */
    private function fitting(InboxItem $item, array $waits, array $resumableRuns, array $releasablePauses, bool $sendAgent, bool $sessionFits): array
    {
        $fits = [
            [InboxActionKind::ResumeRun, self::fitsResumeRun($waits, $resumableRuns)],
            [InboxActionKind::RetryCard, self::fitsRetryCard($waits, $releasablePauses)],
            [InboxActionKind::Review, self::fitsReview($waits)],
            [InboxActionKind::SendAgent, $sendAgent],
            [InboxActionKind::Open, self::fitsOpen($item)],
            [InboxActionKind::StartSession, $sessionFits],
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
     * The card of each item that Send an agent works on now: the watched card of a wait item, or the only card of another item.
     *
     * @param list<InboxItem>     $items
     * @param array<string, Uuid> $watchedCards the card of each wait item, by the RFC 4122 item id
     *
     * @return array<string, SendAgentTarget> keyed by the RFC 4122 item id
     */
    private function sendAgentTargets(array $items, array $watchedCards): array
    {
        $cards = [];
        foreach ($items as $item) {
            $cardId = InboxItemKind::Wait === $item->kind ? ($watchedCards[self::key($item)] ?? null) : self::onlyCardOf($item);
            if (null !== $cardId) {
                $cards[self::key($item)] = [$item->project, $cardId];
            }
        }

        return $this->freeTargets($cards);
    }

    /**
     * The cards that sit in a stage that names work, with no live request of that work and no open run.
     * One read of live requests, and one read of open runs per project.
     *
     * @param array<string, array{Project, Uuid}> $cards the project and the card, by any key
     *
     * @return array<string, SendAgentTarget> by the same key
     */
    private function freeTargets(array $cards): array
    {
        $candidates = [];
        foreach ($cards as $key => [$project, $cardId]) {
            $kind = $this->stageWorks->of($project->id ?? throw new \LogicException('A stored project has an id.'), $cardId);
            if (null !== $kind) {
                $candidates[$key] = [$project, new SendAgentTarget($cardId, $kind)];
            }
        }
        if ([] === $candidates) {
            return [];
        }

        $live = [];
        foreach ($this->workRequests->findLiveRowsForCards(array_values(array_map(static fn (array $candidate): string => $candidate[1]->cardId->toRfc4122(), $candidates))) as $row) {
            $live[$row['card_id'].':'.$row['kind']] = true;
        }
        $cardsByProject = [];
        foreach ($candidates as [$project, $target]) {
            $cardsByProject[(string) $project->id][1][] = $target->cardId;
            $cardsByProject[(string) $project->id][0] = $project;
        }
        $busy = [];
        foreach ($cardsByProject as [$project, $cardIds]) {
            foreach ($this->workerRuns->findCardIdsWithOpenRun($project, $cardIds) as $cardId) {
                $busy[$cardId] = true;
            }
        }

        return array_filter(
            array_map(static fn (array $candidate): SendAgentTarget => $candidate[1], $candidates),
            static fn (SendAgentTarget $target): bool => !isset($live[$target->cardId->toRfc4122().':'.$target->kind]) && !isset($busy[$target->cardId->toRfc4122()]),
        );
    }

    /** The card of a non-wait item that links exactly one card. */
    private static function onlyCardOf(InboxItem $item): ?Uuid
    {
        $link = $item->cards->first();

        return 1 === $item->cards->count() && false !== $link ? $link->card->id : null;
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
     * The kind of the newest open wait when it fits, otherwise Open, so a wait item always has a default that fits.
     *
     * @param list<InboxCardWait>   $waits   open waits, oldest first
     * @param list<InboxActionKind> $fitting
     */
    private static function defaultKind(InboxItem $item, array $waits, array $fitting): ?InboxActionKind
    {
        if (InboxItemKind::Wait !== $item->kind) {
            return null;
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
     * @return array<string, true> the ids of the runs whose control offers Resume with no reason against it
     */
    private function resumableRuns(array $waits): array
    {
        $runIds = [];
        foreach ($waits as $wait) {
            if (InboxCardWaitType::WorkerRun === $wait->type && null !== $wait->runId) {
                $runIds[$wait->runId->toRfc4122()] = $wait->runId->toRfc4122();
            }
        }

        /** @var array<string, list<WorkerRun>> $byProject */
        $byProject = [];
        foreach ($this->workerRuns->findByIds(array_values($runIds)) as $run) {
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
