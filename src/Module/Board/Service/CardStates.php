<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Repository\CardHoldRepository;
use App\Module\Bridge\Service\CardLiveWork;
use App\Module\Bridge\View\CardLiveWorkItem;
use App\Module\Bridge\View\CardRunWarning;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\CardStateHints;
use App\Observability\RequestTimeline;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Uid\Uuid;

/**
 * The state each card of a board page shows: Stuck, Needs you, Working or Waiting. It reads the facts
 * of all cards in a fixed set of queries, so the cost does not grow with the card count.
 */
final readonly class CardStates
{
    private const array FIX_KINDS = ['fix', 'repair'];

    private const array MERGE_KINDS = ['merge'];

    /** @param iterable<CardStateSignalsInterface> $signals */
    public function __construct(
        private CardPauseRepository $cardPauses,
        private BoardAutomation $automation,
        private CardLiveWork $liveWork,
        private CardEventRepository $cardEvents,
        private CardHoldRepository $cardHolds,
        private CardRepository $cards,
        private ForgePullRequestRepository $forgePullRequests,
        private CardPullRequestRepository $cardPullRequests,
        private CardStateHints $hints,
        private ClockInterface $clock,
        private RequestTimeline $timeline,

        #[AutowireIterator('app.card_state_signals')]
        private iterable $signals,
    ) {
    }

    /**
     * @param list<Card>                    $cards       the cards of the project, in any column
     * @param array<string, CardRunWarning> $runWarnings keyed by card id
     * @param array<string, CardPause>|null $paused      the active pauses by card id, when the caller holds them
     *
     * @return array<string, CardState> card id => its state; a card in a terminal column, or with no reason, has no key
     */
    public function forCards(Project $project, array $cards, PullRequestStates $pullRequests, array $runWarnings, ?array $paused = null): array
    {
        $open = array_values(array_filter($cards, static fn (Card $card): bool => !$card->column->terminal));
        if ([] === $open) {
            return [];
        }

        return $this->timeline->span('board.card_states', function () use ($project, $open, $pullRequests, $runWarnings, $paused): array {
            $ids = array_map(static fn (Card $card): string => (string) $card->id, $open);
            $now = $this->clock->now();
            $paused ??= $this->cardPauses->findActiveForCardIds($ids);
            $parentIds = array_values(array_unique(array_map(static fn (Card $card): string => (string) $card->parent?->id, array_filter($open, static fn (Card $card): bool => null !== $card->parent))));
            $work = $this->liveWork->forCards($project, array_values(array_unique([...$ids, ...$parentIds])));
            $settings = $this->automation->settingsOf($project);
            $delay = new \DateInterval(\sprintf('PT%dM', $settings->stuckDelayMinutes));

            $projectId = $project->requireId();
            $parentNumbers = [];
            foreach ($open as $card) {
                if (null !== $card->parent) {
                    $parentNumbers[$card->parent->number] = $card->parent->number;
                }
            }
            $epicBranches = [] === $parentNumbers ? [] : $this->hints->epicBranchesOf($projectId, array_values($parentNumbers));
            $stackParents = $this->stackParentsOf($projectId, $open, $pullRequests, $epicBranches);

            $reasons = [];
            foreach ($open as $card) {
                $id = (string) $card->id;
                foreach ($this->reasonsOf($card, $paused[$id] ?? null, $runWarnings[$id] ?? null, $work[$id] ?? [], $pullRequests, $now, $delay, $stackParents) as $reason) {
                    self::add($reasons, $id, $reason);
                }
            }
            foreach ($this->signals as $source) {
                foreach ($source->signalsFor($project, $open) as $id => $found) {
                    foreach ($found as $reason) {
                        self::add($reasons, $id, $reason);
                    }
                }
            }

            if ($settings->enabled) {
                $this->addQuietPullRequests($project, $open, $reasons, $work, $pullRequests, $now, $delay, $epicBranches);
            }

            $states = [];
            foreach ($ids as $id) {
                $found = array_values($reasons[$id] ?? []);
                if ([] !== $found) {
                    $states[$id] = CardState::of($found);
                }
            }

            return $states;
        }, data: ['cards' => \count($open)]);
    }

    /**
     * @param list<CardLiveWorkItem>                              $work
     * @param array<string, array{pullRequest: ?int, card: ?int}> $stackParents
     *
     * @return list<CardStateReason>
     */
    private function reasonsOf(Card $card, ?CardPause $pause, ?CardRunWarning $warning, array $work, PullRequestStates $pullRequests, \DateTimeImmutable $now, \DateInterval $delay, array $stackParents): array
    {
        $reasons = [];
        if (null !== $pause) {
            $reasons[] = new CardStateReason(CardStateCode::Paused, ['%reason%' => $pause->reason], $pause->createdAt);
        }
        if (null !== $warning) {
            $reasons[] = new CardStateReason(CardStateCode::RunStopped, ['%state%' => $warning->state->value], $warning->endedAt);
        }

        $kinds = array_map(static fn (CardLiveWorkItem $item): ?string => $item->kind, $work);
        $fixLive = [] !== array_intersect($kinds, self::FIX_KINDS);
        $mergeLive = [] !== array_intersect($kinds, self::MERGE_KINDS);
        foreach ($work as $item) {
            $reasons[] = new CardStateReason(
                $item->isRun ? CardStateCode::RunOpen : CardStateCode::WorkRequested,
                ['%kind%' => $item->kind ?? ''],
                $item->since,
            );
        }

        foreach ($card->pullRequests as $link) {
            $view = $pullRequests->of($link);
            if (null === $view || null === $view->refreshedAt || PullRequestState::Open !== $view->state) {
                continue;
            }
            $params = ['%number%' => $link->number ?? 0];
            $waitsForApproval = !$view->draft
                && PullRequestChecks::Passed === $view->checks
                && null !== $view->defaultBranch
                && $view->baseBranch === $view->defaultBranch
                && !$view->approvalCoversHead;

            if (PullRequestChecks::Failed === $view->checks && !$fixLive) {
                $reasons[] = new CardStateReason(CardStateCode::ChecksFailed, $params, $view->checksFailedSince);
            }
            if (PullRequestMergeability::Conflicting === $view->mergeability && !$fixLive) {
                $reasons[] = new CardStateReason(CardStateCode::Conflicting, $params, $view->conflictingSince);
            }
            if ($waitsForApproval) {
                $reasons[] = new CardStateReason(CardStateCode::WaitsForApproval, $params, $view->waitsForApprovalSince);
            }
            if ($view->readyToMerge && null !== $view->readySince && !$waitsForApproval && !$view->mergeInFlight && !$mergeLive && null === $view->forgeRequestedAt) {
                $parent = $stackParents[self::stackKey($link, $view)] ?? null;
                if (null !== $parent) {
                    $reasons[] = self::waitsForParent($parent, $params, $view->baseBranch, $view->readySince);
                } else {
                    $stuckAt = $view->readySince->add($delay);
                    if ($stuckAt <= $now) {
                        $reasons[] = new CardStateReason(CardStateCode::ReadyNotMerged, $params, $stuckAt);
                    }
                }
            }
            if (null !== $view->forgeRequestedAt) {
                $reasons[] = new CardStateReason(CardStateCode::ForgeRequestPending, $params, $view->forgeRequestedAt);
            }
        }

        return $reasons;
    }

    /**
     * Runs after the signals, because it needs to know that no other reason applies.
     *
     * @param list<Card>                                    $open
     * @param array<string, array<string, CardStateReason>> $reasons
     * @param array<string, list<CardLiveWorkItem>>         $work
     * @param array<int, string>                            $epicBranches the epic branch of each parent card number
     */
    private function addQuietPullRequests(Project $project, array $open, array &$reasons, array $work, PullRequestStates $pullRequests, \DateTimeImmutable $now, \DateInterval $delay, array $epicBranches): void
    {
        $candidates = array_filter($open, static fn (Card $card): bool => !isset($reasons[(string) $card->id]) && [] !== $card->pullRequests->toArray());
        if ([] === $candidates) {
            return;
        }
        $projectId = $project->requireId();
        $columns = array_flip($this->hints->pullRequestColumnIds($projectId));
        $candidates = array_filter($candidates, static fn (Card $card): bool => isset($columns[(string) $card->column->id]));
        if ([] === $candidates) {
            return;
        }

        $ids = array_map(static fn (Card $card): string => (string) $card->id, $candidates);
        $held = array_flip($this->cardHolds->findCardIdsOfProject($project));
        $newest = $this->cardEvents->findNewestOccurredAtOfCards(array_values($ids));
        $unfinishedParents = array_flip($this->cards->findIdsWithOpenChildren(array_values($ids)));

        foreach ($candidates as $card) {
            $id = (string) $card->id;
            $parentId = null === $card->parent ? null : (string) $card->parent->id;
            if (isset($held[$id]) || isset($unfinishedParents[$id]) || null === ($newest[$id] ?? null) || (null !== $parentId && [] !== ($work[$parentId] ?? []))) {
                continue;
            }
            $stuckAt = $newest[$id]->add($delay);
            if ($stuckAt > $now) {
                continue;
            }
            $epicBranch = null === $card->parent ? null : $epicBranches[$card->parent->number] ?? null;
            foreach ($card->pullRequests as $link) {
                $view = $pullRequests->of($link);
                if (null === $view || null === $view->refreshedAt || PullRequestState::Open !== $view->state || PullRequestChecks::Pending === $view->checks) {
                    continue;
                }
                if (!self::isStacked($view, $epicBranch)) {
                    self::add($reasons, $id, new CardStateReason(CardStateCode::PullRequestIdle, ['%number%' => $link->number ?? 0], $stuckAt));

                    break;
                }
            }
        }
    }

    /** A pull request is stacked when its base is neither the default branch nor the epic branch of its card. */
    private static function isStacked(PullRequestStateView $view, ?string $epicBranch): bool
    {
        return null !== $view->defaultBranch && $view->baseBranch !== $view->defaultBranch && $view->baseBranch !== $epicBranch;
    }

    private static function stackKey(CardPullRequest $link, PullRequestStateView $view): string
    {
        return $link->forge->value.'/'.mb_strtolower($link->repository ?? '').'/'.$view->baseBranch;
    }

    /**
     * @param array{pullRequest: ?int, card: ?int} $parent
     * @param array<string, int>                   $params
     */
    private static function waitsForParent(array $parent, array $params, ?string $base, \DateTimeImmutable $since): CardStateReason
    {
        if (null !== $parent['pullRequest'] && null !== $parent['card']) {
            return new CardStateReason(CardStateCode::WaitsForParentCard, [...$params, '%parent_number%' => $parent['card'], '%pull_request%' => $parent['pullRequest']], $since);
        }

        return new CardStateReason(CardStateCode::WaitsForParentBranch, [...$params, '%branch%' => $base ?? ''], $since);
    }

    /**
     * The open pull request that owns the base branch of each ready, stacked pull request, in two queries at most.
     * A base with no stored pull request counts as a branch that waits. A base whose pull requests all merged or closed has no entry.
     *
     * @param list<Card>         $open
     * @param array<int, string> $epicBranches
     *
     * @return array<string, array{pullRequest: ?int, card: ?int}> stack key => the parent
     */
    private function stackParentsOf(Uuid $projectId, array $open, PullRequestStates $pullRequests, array $epicBranches): array
    {
        $wanted = [];
        foreach ($open as $card) {
            $epicBranch = null === $card->parent ? null : $epicBranches[$card->parent->number] ?? null;
            foreach ($card->pullRequests as $link) {
                $view = $pullRequests->of($link);
                if (null === $view || null === $view->refreshedAt || PullRequestState::Open !== $view->state || !$view->readyToMerge || null === $view->readySince
                    || $view->mergeInFlight || null !== $view->forgeRequestedAt || null === $link->repository || null === $view->baseBranch || !self::isStacked($view, $epicBranch)) {
                    continue;
                }
                $wanted[self::stackKey($link, $view)] = ['forge' => $link->forge->value, 'repository' => mb_strtolower($link->repository), 'headBranch' => $view->baseBranch];
            }
        }
        if ([] === $wanted) {
            return [];
        }

        $openRows = [];
        $seen = [];
        foreach ($this->forgePullRequests->findByHeadBranches($projectId, array_values($wanted)) as $row) {
            $key = $row->forge.'/'.$row->repository.'/'.$row->headBranch;
            $seen[$key] = true;
            if (PullRequestState::Open === $row->state && !isset($openRows[$key])) {
                $openRows[$key] = $row;
            }
        }
        $cardNumbers = $this->cardPullRequests->findCardNumbersOfPullRequests($projectId, array_values(array_map(
            static fn ($row): array => ['forge' => $row->forge, 'repository' => $row->repository, 'number' => $row->number],
            $openRows,
        )));

        $parents = [];
        foreach (array_keys($wanted) as $key) {
            if (isset($openRows[$key])) {
                $row = $openRows[$key];
                $parents[$key] = ['pullRequest' => $row->number, 'card' => $cardNumbers[$row->forge.'/'.$row->repository.'/'.$row->number] ?? null];
            } elseif (!isset($seen[$key])) {
                $parents[$key] = ['pullRequest' => null, 'card' => null];
            }
        }

        return $parents;
    }

    /**
     * Keeps one reason for each code of a card, the one that began first.
     *
     * @param array<string, array<string, CardStateReason>> $reasons
     */
    private static function add(array &$reasons, string $cardId, CardStateReason $reason): void
    {
        $kept = $reasons[$cardId][$reason->code->value] ?? null;
        if (null === $kept || (null !== $reason->since && (null === $kept->since || $reason->since < $kept->since))) {
            $reasons[$cardId][$reason->code->value] = $reason;
        }
    }
}
