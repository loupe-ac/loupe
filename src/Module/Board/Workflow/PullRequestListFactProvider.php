<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\CardPullRequests;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState as ForgePullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Forge\Service\ForgePullRequestApprovals;
use App\Module\Forge\Service\ForgePullRequestWrites;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\ChecksState;
use App\Module\Workflow\Contract\EpicBranches;
use App\Module\Workflow\Contract\FingerprintValue;
use App\Module\Workflow\Contract\ForgeRequest;
use App\Module\Workflow\Contract\PullRequestFacts;
use App\Module\Workflow\Contract\PullRequestList;
use App\Module\Workflow\Contract\PullRequestState;
use App\Module\Workflow\Contract\StackParent;
use Symfony\Component\Uid\Uuid;

/** The pull requests that Forge tracks for the links of the card. */
final readonly class PullRequestListFactProvider extends BoardFactProvider
{
    public function __construct(
        private CardRepository $cards,
        private CardPullRequests $linkedPullRequests,
        private ForgePullRequestRepository $forgePullRequests,
        private EpicBranches $epicBranches,
        private CardPullRequestRepository $cardPullRequests,
        private WorkRequestRepository $workRequests,
        private ForgePullRequestApprovals $approvals,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return PullRequestList::class;
    }

    #[\Override]
    public function legacyGroup(): string
    {
        return 'pull-requests';
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        $stored = $this->cards->find($card->id) ?? throw new \LogicException('A stored card has an id.');
        $parentEpicBranch = null === $stored->parent ? null : $this->epicBranches->of($stored->project->requireId(), $stored->parent->number);

        $pullRequests = $this->linkedPullRequests->forCard($stored);
        $epicRepositories = $this->epicRepositories($stored, $parentEpicBranch);
        $primary = $this->linkedPullRequests->primary($pullRequests);
        $fixes = $this->workRequests->findFinishedFixesOpenedAt([$card->id])[$card->id->toRfc4122()] ?? [];
        $facts = self::inSubjectOrder($pullRequests, array_map(fn (ForgePullRequest $pullRequest): PullRequestFacts => $this->pullRequestFacts($pullRequest, $parentEpicBranch, $epicRepositories, $fixes), $pullRequests));

        return new PullRequestList(
            $facts,
            null === $primary ? null : array_find($facts, static fn (PullRequestFacts $facts): bool => true === $primary->id?->equals($facts->id)),
        );
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof PullRequestList || throw new \LogicException('The provider fingerprints its own facts.');

        return FingerprintValue::sorted(array_map(static fn (PullRequestFacts $pullRequest): array => $pullRequest->fingerprint(), $facts->pullRequests));
    }

    /**
     * Once the epic links its pull request, that pull request names the repository of the epic branch.
     * A repository whose epic pull requests all finished has no epic branch any more, so a late child does not merge into it.
     *
     * @return array<string, bool> whether each repository of the epic pull requests has one open, empty before one exists
     */
    private function epicRepositories(Card $card, ?string $parentEpicBranch): array
    {
        $epicRepositories = [];
        if (null !== $card->parent && null !== $parentEpicBranch) {
            foreach ($this->linkedPullRequests->forCard($card->parent) as $epicPullRequest) {
                if ($parentEpicBranch === $epicPullRequest->headBranch) {
                    $key = self::repositoryKey($epicPullRequest);
                    $epicRepositories[$key] = ($epicRepositories[$key] ?? false) || ForgePullRequestState::Open === $epicPullRequest->state;
                }
            }
        }

        return $epicRepositories;
    }

    /**
     * @param ?string                                                                                   $epicBranch       the epic branch of the parent of the card, or null
     * @param array<string, bool>                                                                       $epicRepositories whether each repository of the epic pull requests has one open, empty before one exists
     * @param list<array{forge: string, repository: string, number: int, openedAt: \DateTimeImmutable}> $fixes            the done fix requests of the card
     */
    private function pullRequestFacts(ForgePullRequest $pullRequest, ?string $epicBranch, array $epicRepositories, array $fixes): PullRequestFacts
    {
        $base = $pullRequest->baseBranch;
        $baseIsEpicBranch = null !== $base && $base === $epicBranch
            && ([] === $epicRepositories || ($epicRepositories[self::repositoryKey($pullRequest)] ?? false));

        $parents = [];
        $projectId = $pullRequest->project->id ?? throw new \LogicException('A stored pull request has a project id.');
        if (null !== $base && $base !== $pullRequest->defaultBranch && !$baseIsEpicBranch) {
            $parents = array_values(array_filter(
                $this->forgePullRequests->findByHeadBranch($projectId, $pullRequest->forge, $pullRequest->repository, $base),
                static fn (ForgePullRequest $parent): bool => $parent->id?->toRfc4122() !== $pullRequest->id?->toRfc4122(),
            ));
        }
        $open = ForgePullRequestState::Open === $pullRequest->state;
        $headSha = $pullRequest->headSha;
        $appApprovalStands = $open && null !== $pullRequest->appApprovalId;
        $appApprovalCoversHead = $appApprovalStands && null !== $headSha && $pullRequest->appApprovalSha === $headSha;
        $personApprovalCoversHead = PullRequestReview::Approved === $pullRequest->review
            && null !== $pullRequest->coveredSha
            && $pullRequest->coveredSha === $headSha;

        return new PullRequestFacts(
            state: PullRequestState::from($pullRequest->state->value),
            draft: $pullRequest->draft,
            checks: match ($pullRequest->checks) {
                PullRequestChecks::Pending => ChecksState::Pending,
                PullRequestChecks::Passed => ChecksState::Passed,
                PullRequestChecks::Failed => ChecksState::Failed,
            },
            conflicting: PullRequestMergeability::Conflicting === $pullRequest->mergeability,
            behind: PullRequestMergeability::Behind === $pullRequest->mergeability,
            approvalsCoveringHead: ($personApprovalCoversHead ? 1 : 0) + ($appApprovalCoversHead ? 1 : 0),
            changesRequested: $pullRequest->changesRequestOpen($pullRequest->lastFixOpenedAt($fixes)),
            baseIsMergeTarget: $baseIsEpicBranch || (null !== $base && $base === $pullRequest->defaultBranch),
            baseIsEpicBranch: $baseIsEpicBranch,
            stacked: [] !== $parents,
            parentMerged: array_any($parents, static fn (ForgePullRequest $parent): bool => ForgePullRequestState::Merged === $parent->state),
            closedAt: match ($pullRequest->state) {
                ForgePullRequestState::Open => null,
                ForgePullRequestState::Merged => $pullRequest->mergedAt,
                // Forge records no close time, so the last read of the pull request stands in for it.
                ForgePullRequestState::Closed => $pullRequest->refreshedAt,
            },
            id: $pullRequest->id,
            headSha: $pullRequest->headSha,
            number: $pullRequest->number,
            stackParent: null !== $base && null !== $pullRequest->defaultBranch && $base !== $pullRequest->defaultBranch && $base !== $epicBranch
                ? $this->stackParent($projectId, $base, $parents)
                : null,
            waitsForApproval: $open
                && !$pullRequest->draft
                && PullRequestChecks::Passed === $pullRequest->checks
                && null !== $pullRequest->defaultBranch
                && $base === $pullRequest->defaultBranch
                && !(PullRequestReview::Approved === $pullRequest->review && null !== $pullRequest->approvalId && !$pullRequest->approvalIsStale())
                && !$appApprovalCoversHead,
            readyToMerge: $pullRequest->readyToMerge,
            readySince: $pullRequest->readySince,
            checksFailedSince: $pullRequest->checksFailedSince,
            checksPendingSince: $pullRequest->checksPendingSince,
            conflictingSince: $pullRequest->conflictingSince,
            forgeRequests: $open ? self::forgeRequests($pullRequest) : [],
            checksOnHead: null !== $pullRequest->headSha && $pullRequest->checksSha === $pullRequest->headSha,
            canMerge: \in_array($pullRequest->mergeability, [PullRequestMergeability::Mergeable, PullRequestMergeability::Behind, PullRequestMergeability::Blocked], true),
            baseIsDefault: null !== $base && $base === $pullRequest->defaultBranch,
            appApprovalCoversHead: $appApprovalCoversHead,
            appApprovalStands: $appApprovalStands,
            appCanApprove: $open && $this->approvals->canApprove($pullRequest),
        );
    }

    /**
     * The first open pull request whose head is the base, as the board shows it. A base with no stored pull request waits for the branch alone.
     *
     * @param list<ForgePullRequest> $parents the other pull requests whose head is the base
     */
    private function stackParent(Uuid $projectId, string $base, array $parents): ?StackParent
    {
        if ([] === $parents) {
            return new StackParent($base);
        }
        $open = array_filter($parents, static fn (ForgePullRequest $parent): bool => ForgePullRequestState::Open === $parent->state);
        if ([] === $open) {
            return null;
        }
        usort($open, static fn (ForgePullRequest $a, ForgePullRequest $b): int => $a->number <=> $b->number);
        $parent = $open[0];
        $cards = $this->cardPullRequests->findCardNumbersOfPullRequests($projectId, [['forge' => $parent->forge, 'repository' => $parent->repository, 'number' => $parent->number]]);

        return new StackParent($base, $parent->number, $cards[$parent->forge.'/'.$parent->repository.'/'.$parent->number] ?? null);
    }

    /** @return list<ForgeRequest> */
    private static function forgeRequests(ForgePullRequest $pullRequest): array
    {
        $requests = [];
        if (null !== $pullRequest->mergeRequestedSha && null !== $pullRequest->mergeRequestedAt) {
            $requests[] = self::forgeRequest($pullRequest->mergeRequestedAt, merge: true);
        }
        if (null !== $pullRequest->syncFromSha && null !== $pullRequest->syncRequestedAt) {
            $requests[] = self::forgeRequest($pullRequest->syncRequestedAt);
        }
        if (null !== $pullRequest->baseChangeRequestedTo && null !== $pullRequest->baseChangeRequestedAt) {
            $requests[] = self::forgeRequest($pullRequest->baseChangeRequestedAt);
        }

        return $requests;
    }

    private static function forgeRequest(\DateTimeImmutable $requestedAt, bool $merge = false): ForgeRequest
    {
        return new ForgeRequest($requestedAt, $requestedAt->modify(\sprintf('+%d seconds', ForgePullRequestWrites::MARKER_LIFETIME_SECONDS)), $merge);
    }

    /**
     * The unstacked pull requests first, then the oldest opened. One with no opening time sorts first, and the id breaks a tie.
     *
     * @param list<ForgePullRequest> $pullRequests
     * @param list<PullRequestFacts> $facts        the facts of each pull request, at the same index
     *
     * @return list<PullRequestFacts>
     */
    private static function inSubjectOrder(array $pullRequests, array $facts): array
    {
        $order = array_keys($facts);
        $key = static fn (int $i): array => [$facts[$i]->stacked, $pullRequests[$i]->openedAt?->format('Y-m-d\TH:i:s.u') ?? '', (string) $pullRequests[$i]->id];
        usort($order, static fn (int $a, int $b): int => $key($a) <=> $key($b));

        return array_map(static fn (int $i): PullRequestFacts => $facts[$i], $order);
    }

    private static function repositoryKey(ForgePullRequest $pullRequest): string
    {
        return $pullRequest->forge.' '.mb_strtolower($pullRequest->repository);
    }
}
