<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Workflow\Contract\FactsPass;
use App\Module\Workflow\Contract\PullRequestFacts;

/** The pull requests that Forge tracks for the links of a card, and the one the engine acts on. */
final readonly class CardPullRequests
{
    public function __construct(
        private CardPullRequestRepository $cardPullRequests,
        private ForgePullRequestRepository $forgePullRequests,
        private FactsPass $factsPass,
    ) {
    }

    /**
     * A link that names no pull request, or that Forge does not track, gives nothing.
     * A reference gives nothing either: a pull request that finished before the link of
     * the card to it, so it is not the work of the card.
     *
     * @return list<ForgePullRequest> by forge, repository and number
     */
    public function forCard(Card $card): array
    {
        return $this->factsPass->remember('card-pull-requests:'.$card->id, fn (): array => $this->readForCard($card));
    }

    /** @return list<ForgePullRequest> */
    private function readForCard(Card $card): array
    {
        $keys = [];
        $cutOffs = [];
        foreach ($this->cardPullRequests->findCurrentKeys($card) as $link) {
            if (null === $link['repository'] || null === $link['number']) {
                continue;
            }
            $keys[] = ['forge' => $link['forge'], 'repository' => $link['repository'], 'number' => $link['number']];
            $cutOff = $link['predates_link_times'] ? $card->createdAt : $link['added_at'];
            $id = self::identity($link['forge'], $link['repository'], $link['number']);
            if (!isset($cutOffs[$id]) || $cutOff < $cutOffs[$id]) {
                $cutOffs[$id] = $cutOff;
            }
        }

        $projectId = $card->project->id ?? throw new \LogicException('A stored card has a project id.');
        $pullRequests = array_filter(
            $this->forgePullRequests->findByKeys($projectId, $keys),
            static function (ForgePullRequest $pullRequest) use ($cutOffs): bool {
                $finishedAt = self::finishedAt($pullRequest);
                $cutOff = $cutOffs[self::identity($pullRequest->forge, $pullRequest->repository, $pullRequest->number)] ?? null;

                return null === $finishedAt || null === $cutOff || $finishedAt > $cutOff;
            },
        );
        $pullRequests = array_values($pullRequests);
        usort($pullRequests, static fn (ForgePullRequest $a, ForgePullRequest $b): int => [$a->forge, $a->repository, $a->number] <=> [$b->forge, $b->repository, $b->number]);

        return $pullRequests;
    }

    private static function finishedAt(ForgePullRequest $pullRequest): ?\DateTimeImmutable
    {
        return match ($pullRequest->state) {
            PullRequestState::Merged => $pullRequest->mergedAt,
            PullRequestState::Closed => $pullRequest->closedAt,
            PullRequestState::Open => null,
        };
    }

    private static function identity(string $forge, string $repository, int $number): string
    {
        return $forge.'/'.mb_strtolower($repository).'/'.$number;
    }

    public function childMergedInto(Card $parent, string $baseBranch): bool
    {
        return $this->cardPullRequests->hasChildMergedInto($parent, $baseBranch);
    }

    /** Whether the card links the pull request, whatever case its link spells the repository in. */
    public function links(Card $card, string $forge, string $repository, int $number): bool
    {
        return null !== $this->cardPullRequests->findUrlOfPullRequest($card, $forge, $repository, $number);
    }

    /** @return list<string> every link URL of the card, read past the identity map */
    public function currentUrls(Card $card): array
    {
        return $this->cardPullRequests->findCurrentUrls($card);
    }

    /**
     * The pull requests of the children of the card that merged into the branch, as Forge tracks them.
     *
     * @return list<ForgePullRequest>
     */
    public function childrenMergedInto(Card $parent, string $baseBranch): array
    {
        $keys = $this->cardPullRequests->findKeysOfChildrenMergedInto($parent, $baseBranch);

        return $this->forgePullRequests->findByKeys($parent->project->requireId(), $keys);
    }

    /** The pull request of a child of the card that merged into the branch last, as Forge tracks it. */
    public function lastChildMergedInto(Card $parent, string $baseBranch): ?ForgePullRequest
    {
        $key = $this->cardPullRequests->findLastChildMergedInto($parent, $baseBranch);
        if (null === $key) {
            return null;
        }
        $projectId = $parent->project->id ?? throw new \LogicException('A stored card has a project id.');

        return $this->forgePullRequests->findByKeys($projectId, [$key])[0] ?? null;
    }

    /**
     * The open pull request opened last, else the pull request opened last. A pull request
     * with no opening time sorts first, and the id breaks a tie.
     *
     * @param list<ForgePullRequest> $pullRequests
     */
    public function primary(array $pullRequests): ?ForgePullRequest
    {
        $open = array_filter($pullRequests, static fn (ForgePullRequest $pullRequest): bool => PullRequestState::Open === $pullRequest->state);
        $candidates = [] === $open ? $pullRequests : $open;

        $primary = null;
        foreach ($candidates as $pullRequest) {
            if (null === $primary || self::order($pullRequest) > self::order($primary)) {
                $primary = $pullRequest;
            }
        }

        return $primary;
    }

    /**
     * The pull request the facts read, by its id. Facts with no id fall back to the primary one.
     *
     * @param list<ForgePullRequest> $pullRequests
     */
    public function subjectOf(array $pullRequests, ?PullRequestFacts $facts): ?ForgePullRequest
    {
        if (null === $facts?->id) {
            return $this->primary($pullRequests);
        }

        return array_find($pullRequests, static fn (ForgePullRequest $pullRequest): bool => true === $pullRequest->id?->equals($facts->id));
    }

    /** @return array{string, string} */
    private static function order(ForgePullRequest $pullRequest): array
    {
        return [$pullRequest->openedAt?->format('Y-m-d\TH:i:s.u') ?? '', (string) $pullRequest->id];
    }
}
