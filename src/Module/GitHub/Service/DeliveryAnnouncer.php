<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Event\ForgeBranchPushed;
use App\Module\Forge\Event\ForgeDeliveryReceived;
use App\Module\Forge\Event\ForgePullRequestBranchChanged;
use App\Module\Forge\ForgeDelivery;
use App\Module\Forge\ForgeEventType;
use App\Module\Forge\Service\ForgeClaim;
use App\Module\Forge\Service\ForgeClaimOutcome;
use App\Module\Forge\Service\ForgeRepositories;
use App\Module\Forge\Service\PullRequestRefreshTarget;
use App\Module\Forge\Service\PullRequestTracker;
use App\Module\GitHub\GitHubDelivery;
use App\Module\GitHub\GitHubRepositoryRef;
use App\Module\GitHub\PullRequestRefreshHint;
use App\Module\Project\Entity\Project;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/** Claims the repository a delivery names for one project, then announces what the delivery says and queues the refreshes it hints. */
final readonly class DeliveryAnnouncer
{
    public function __construct(
        private ForgeRepositories $forgeRepositories,
        private EventDispatcherInterface $events,
        private PullRequestTracker $tracker,
        private LoggerInterface $logger,
        private GitHubAppConfiguration $configuration,
        private RepositoryWorkflowReads $workflowReads,
    ) {
    }

    /**
     * Claims the repository, and announces a move when the claim found this
     * project's row under an older path. Answers null when the claim is refused.
     */
    public function claim(Project $project, GitHubRepositoryRef $repository, ForgeRepositorySource $source, ?int $installationId = null): ?ForgeClaim
    {
        $claim = $this->forgeRepositories->claim($project, GitHubDelivery::FORGE, $repository->externalId(), $repository->fullName, $source, null === $installationId ? null : (string) $installationId);
        if (ForgeClaimOutcome::Refused === $claim->outcome || null === $claim->repository) {
            $this->logger->info('github.repository_refused', [
                'projectId' => (string) $project->id,
                'repositoryId' => $repository->id,
                'installationId' => $installationId,
            ]);

            return null;
        }

        if (null !== $claim->movedFrom) {
            $this->dispatch($project, [new ForgeDelivery(ForgeEventType::REPOSITORY_MOVED, GitHubDelivery::FORGE, $claim->movedFrom, movedTo: $repository->fullName)], $source);
        }

        if (ForgeRepositorySource::Installation === $source && (ForgeClaimOutcome::Owned === $claim->outcome || $claim->tookOver)) {
            $this->workflowReads->queue($claim->repository);
        }

        return $claim;
    }

    /** Tells the project that a repository it holds changed path, so its stored links follow. */
    public function moved(Project $project, string $from, string $to): void
    {
        $this->dispatch($project, [new ForgeDelivery(ForgeEventType::REPOSITORY_MOVED, GitHubDelivery::FORGE, $from, movedTo: $to)], ForgeRepositorySource::Installation);
    }

    public function announce(Project $project, GitHubRepositoryRef $repository, GitHubDelivery $delivery, ForgeRepositorySource $source, ?int $installationId = null): void
    {
        $claim = $this->claim($project, $repository, $source, $installationId);
        if (null === $claim?->repository) {
            return;
        }

        $this->forgeRepositories->accepted($claim->repository, new \DateTimeImmutable());

        $deliveries = $delivery->forgeDeliveries();
        if ([] !== $deliveries) {
            $this->dispatch($project, $deliveries, $source);
        }

        if ($delivery->pushedDefaultBranch() && ForgeRepositorySource::Installation === $claim->repository->source) {
            $this->workflowReads->queue($claim->repository);
        }

        $pushedBranch = $delivery->pushedBranch();
        if (null !== $pushedBranch) {
            $this->events->dispatch(new ForgeBranchPushed($project->id ?? throw new \LogicException('A claimed project has an id.'), GitHubDelivery::FORGE, $repository->fullName, $pushedBranch));
        }

        $pullRequestBranch = $delivery->pullRequestHeadBranch();
        if (null !== $pullRequestBranch) {
            $this->events->dispatch(new ForgePullRequestBranchChanged($project->id ?? throw new \LogicException('A claimed project has an id.'), GitHubDelivery::FORGE, $repository->fullName, $pullRequestBranch));
        }

        // A repository with no state reads gets the bare events alone, so its review verdict never reaches the automation.
        $verdicts = $this->stateReadable($source);
        $targets = array_map(
            static fn (PullRequestRefreshHint $hint): PullRequestRefreshTarget => match (true) {
                null !== $hint->number => PullRequestRefreshTarget::number($hint->number, $verdicts ? $hint->verdict : null, $verdicts ? $hint->reviewId : null),
                null !== $hint->headSha => PullRequestRefreshTarget::head($hint->headSha),
                default => PullRequestRefreshTarget::base($hint->baseBranch ?? throw new \LogicException('A hint sets one field.')),
            },
            $delivery->refreshHints(),
        );
        if ([] !== $targets) {
            $this->tracker->refreshAll($project, GitHubDelivery::FORGE, $repository->fullName, $targets);
        }
    }

    /** @param non-empty-list<ForgeDelivery> $deliveries */
    private function dispatch(Project $project, array $deliveries, ForgeRepositorySource $source): void
    {
        $this->events->dispatch(new ForgeDeliveryReceived($project->id ?? throw new \LogicException('A claimed project has an id.'), $deliveries, $this->stateReadable($source)));
    }

    /** A state read needs an installation token, and only the App id and private key mint one. */
    private function stateReadable(ForgeRepositorySource $source): bool
    {
        return ForgeRepositorySource::Installation === $source && $this->configuration->canReadForge();
    }
}
