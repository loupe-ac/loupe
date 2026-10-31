<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Repository\CardRepository;
use App\Module\Forge\Service\BranchReaders;
use App\Module\Project\Workshop\WorkshopConnectionsProviderInterface;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\EpicBranches;

final readonly class EpicBranchExistsFactProvider extends BoardFactProvider
{
    public function __construct(
        private CardRepository $cards,
        private EpicBranches $epicBranches,
        private WorkshopConnectionsProviderInterface $workshop,
        private BranchReaders $branchReaders,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return EpicBranchExistsFacts::class;
    }

    #[\Override]
    public function legacyGroup(): string
    {
        return 'epic-branch-exists';
    }

    /**
     * The epic branch exists when each live bridge reports a repository that holds it. Anything the app cannot
     * prove reads false: no live bridge, a remote of no connected repository, or a forge that does not answer.
     * A card in the Backlog or a terminal column reads true without a read, because the engine builds every provider.
     */
    #[\Override]
    public function build(CardSnapshot $card): object
    {
        if ($card->column->backlog || $card->column->terminal) {
            return new EpicBranchExistsFacts(true);
        }
        $stored = $this->cards->find($card->id) ?? throw new \LogicException('A stored card has an id.');
        $branch = null === $stored->parent ? null : $this->epicBranches->of($card->projectId, $stored->parent->number);
        if (null === $branch) {
            return new EpicBranchExistsFacts(true);
        }

        $remotes = [];
        foreach ($this->workshop->forProject($stored->project) as $connection) {
            if (!$connection->quiet && null !== $connection->repositoryPath) {
                $remotes[] = $connection->repositoryRemoteUrl ?? '';
            }
        }
        if ([] === $remotes) {
            return new EpicBranchExistsFacts(false);
        }

        foreach (array_unique($remotes) as $remote) {
            if ('' === $remote || !$this->branchReaders->exists($stored->project, $remote, $branch)) {
                return new EpicBranchExistsFacts(false);
            }
        }

        return new EpicBranchExistsFacts(true);
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof EpicBranchExistsFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return $facts->exists;
    }
}
