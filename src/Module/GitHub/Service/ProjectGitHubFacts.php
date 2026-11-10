<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositoryCheck;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Repository\ForgeRepositoryCheckRepository;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\Forge\Settings\RepositorySettings;
use App\Module\GitHub\GitHubDelivery;
use App\Module\GitHub\Repository\GitHubInstallationRepository;
use App\Module\GitHub\View\GitHubRepositoryFacts;
use App\Module\GitHub\View\GitHubSiteFacts;
use App\Module\Workflow\Contract\WorkflowSettings;
use Symfony\Component\Uid\Uuid;

/**
 * The GitHub facts of the projects of one owner, for the project list that
 * `loupe init` reads. They come from the stored repository check and the
 * workflow settings, so the list makes no call to GitHub.
 */
final readonly class ProjectGitHubFacts
{
    public const string MERGE_APPROVAL = 'merge-approval';

    public function __construct(
        private GitHubInstallationRepository $gitHubInstallations,
        private ForgeRepositoryRepository $forgeRepositories,
        private ForgeRepositoryCheckRepository $forgeRepositoryChecks,
        private WorkflowSettings $settings,
    ) {
    }

    /** @return array<string, GitHubSiteFacts> the facts by project id; a project with no GitHub link reads as no app and no repository */
    public function forOwner(User $owner): array
    {
        $live = [];
        foreach ($this->gitHubInstallations->findByOwner($owner) as $installation) {
            if (null === $installation->removedAt && null === $installation->suspendedAt) {
                $live[(string) $installation->installationId] = true;
            }
        }

        $repositories = array_values(array_filter(
            $this->forgeRepositories->findByOwner($owner),
            static fn (ForgeRepository $repository): bool => GitHubDelivery::FORGE === $repository->forge,
        ));
        $installed = [];
        foreach ($repositories as $repository) {
            if (ForgeRepositorySource::Installation === $repository->source && null === $repository->accessLostAt && isset($live[$repository->sourceRef ?? ''])) {
                $installed[(string) $repository->project->id] = true;
            }
        }
        $checks = $this->forgeRepositoryChecks->findByRepositories($repositories);

        $byProject = [];
        foreach ($repositories as $repository) {
            $id = $repository->id ?? throw new \LogicException('The repository is not flushed.');
            $byProject[(string) $repository->project->id][] = $this->facts($repository, $id, $checks[(string) $id] ?? null, $live);
        }

        $facts = [];
        foreach (array_keys($installed + $byProject) as $projectId) {
            $facts[$projectId] = new GitHubSiteFacts(isset($installed[$projectId]), $byProject[$projectId] ?? []);
        }

        return $facts;
    }

    /** @param array<array-key, true> $live the ids of the installations that are neither suspended nor removed */
    private function facts(ForgeRepository $repository, Uuid $id, ?ForgeRepositoryCheck $check, array $live): GitHubRepositoryFacts
    {
        $settings = null;
        if (null !== $check?->settings) {
            try {
                $settings = RepositorySettings::fromArray($check->settings);
            } catch (\InvalidArgumentException) {
                $settings = null;
            }
        }

        $rulesRead = match (true) {
            null !== $check?->failure => GitHubRepositoryFacts::RULES_REFUSED,
            null === $settings => null,
            null !== $settings->rulesUnavailable => GitHubRepositoryFacts::RULES_PLAN_LIMITED,
            default => GitHubRepositoryFacts::RULES_OK,
        };
        $merge = $this->settings->forRepository($repository->project->id ?? throw new \LogicException('The project is not flushed.'), $id, self::MERGE_APPROVAL);

        return new GitHubRepositoryFacts(
            $repository->path,
            ForgeRepositorySource::Installation === $repository->source && null === $repository->accessLostAt && isset($live[$repository->sourceRef ?? '']),
            $settings?->defaultBranch,
            $rulesRead,
            match ($rulesRead) {
                GitHubRepositoryFacts::RULES_OK => $settings?->rules->requiresReview,
                GitHubRepositoryFacts::RULES_PLAN_LIMITED => false,
                default => null,
            },
            \is_string($merge->value) ? $merge->value : null,
            $merge->source->value,
        );
    }
}
