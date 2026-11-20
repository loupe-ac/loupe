<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\AgentReview\Repository\AgentReviewRepository;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Inbox\View\WorkLimitRound;
use App\Module\Inbox\View\WorkLimitRoundFinding;
use App\Module\Inbox\View\WorkLimitStory;
use App\Module\Workflow\Contract\EpicBranches;
use App\Module\Workflow\Contract\PauseKind;
use App\Module\Workflow\Contract\RuleWorkLimits;
use Symfony\Component\Uid\Uuid;

/** Reads what a rule did on a card before a work-limit pause stopped it, so the inbox item can say what looped. */
final readonly class WorkLimitStories
{
    /** The reason of a request that answers a failed agent review. */
    private const string AGENT_REVIEW = 'agent-review';

    public function __construct(
        private RuleWorkLimits $ruleWorkLimits,
        private ForgePullRequestRepository $forgePullRequests,
        private WorkerRunRepository $workerRuns,
        private WorkRequestRepository $workRequests,
        private AgentReviewRepository $agentReviews,
        private CardRepository $cards,
        private EpicBranches $epicBranches,
    ) {
    }

    /** Null for a pause of another kind, or when the template no longer names the rule as one with a limit. */
    public function of(CardPause $pause): ?WorkLimitStory
    {
        $cardId = $pause->card->id;
        $projectId = $pause->project->id;
        if (PauseKind::WorkLimit !== $pause->kind || null === $cardId || null === $projectId) {
            return null;
        }
        $limit = $this->ruleWorkLimits->of($projectId, $cardId, $pause->ruleId);
        if (null === $limit) {
            return null;
        }

        $pullRequest = null === $limit->pullRequestId ? null : $this->forgePullRequests->find($limit->pullRequestId);
        $review = null;
        if (null !== $pullRequest && self::AGENT_REVIEW === $limit->reason) {
            $review = $this->agentReviews->findLatestOfHeads([$pullRequest])[(string) $pullRequest->id] ?? null;
        }
        $failed = AgentReviewConclusion::Failure === $review?->conclusion;
        $requests = $this->workRequests->findCountedRoundsOfRule($pause->project, $cardId, $pause->ruleId, $pause->createdAt);
        $runsOfRequest = [];
        foreach ($this->workerRuns->findOfWorkRequests(array_map(static fn (WorkRequest $request): Uuid => $request->id ?? throw new \LogicException('A stored request has an id.'), $requests)) as $run) {
            $runsOfRequest[(string) $run->workRequestId][] = $run;
        }
        $rounds = array_map(static fn (WorkRequest $request): WorkLimitRound => new WorkLimitRound($request, $runsOfRequest[(string) $request->id] ?? []), $requests);
        $runs = array_merge(...array_map(static fn (WorkLimitRound $round): array => array_reverse($round->runs), $rounds));
        $lowest = [] === $rounds ? null : array_last($rounds)->request->round;
        $github = null !== $pullRequest && 'github' === $pullRequest->forge;

        return new WorkLimitStory(
            $limit->workKind,
            $limit->reason,
            $limit->limit,
            $pullRequest?->number,
            $github ? \sprintf('https://github.com/%s/pull/%d', $pullRequest->repository, $pullRequest->number) : null,
            $github && null !== $review?->checkRunId ? \sprintf('https://github.com/%s/runs/%d', $pullRequest->repository, $review->checkRunId) : null,
            $failed,
            $failed ? array_map(static fn (AgentReviewFinding $finding): string => $finding->title, $review->findings()) : [],
            $rounds,
            null === $lowest ? $limit->limit : max(0, $lowest - 1),
            null !== $pullRequest && self::AGENT_REVIEW === $limit->reason ? $this->history($pullRequest, $rounds, $pause->createdAt) : [],
            $runs,
            $failed ? $this->openChildren($pause->card) : [],
            $failed ? $this->epicBranches->of($projectId, $pause->card->number) : null,
        );
    }

    /**
     * The failed review that each round answered: the newest one before its request opened.
     *
     * @param list<WorkLimitRound> $rounds newest first
     *
     * @return list<WorkLimitRoundFinding>
     */
    private function history(ForgePullRequest $pullRequest, array $rounds, \DateTimeImmutable $to): array
    {
        if ([] === $rounds) {
            return [];
        }
        $reviews = $this->agentReviews->findFailedOfPullRequestBetween($pullRequest, array_last($rounds)->request->createdAt, $to);
        $history = [];
        foreach ($rounds as $round) {
            $answered = array_find($reviews, static fn (AgentReview $review): bool => $review->createdAt < $round->request->createdAt);
            $titles = null === $answered ? [] : array_map(static fn (AgentReviewFinding $finding): string => $finding->title, $answered->findings());
            if ([] !== $titles) {
                $history[] = new WorkLimitRoundFinding($round->request->round ?? 0, $titles);
            }
        }

        return $history;
    }

    /** @return list<int> */
    private function openChildren(Card $card): array
    {
        $open = array_filter($this->cards->findChildren($card), static fn (Card $child): bool => !$child->column->terminal);
        $numbers = array_values(array_map(static fn (Card $child): int => $child->number, $open));
        sort($numbers);

        return $numbers;
    }
}
