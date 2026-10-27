<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Service\RepositorySettingsReader;
use App\Module\Forge\Service\RepositorySettingsUnreadable;
use App\Module\Forge\Settings\RepositoryRules;
use App\Module\Forge\Settings\RepositoryRuleset;
use App\Module\Forge\Settings\RepositorySettings;
use App\Module\GitHub\GitHubDelivery;

/**
 * Reads the settings of a repository as the App installation that delivers it
 * to the project. It sends GET requests alone, and keeps nothing between two
 * reads, so each check sees the repository as it stands.
 */
final readonly class GitHubRepositorySettingsReader implements RepositorySettingsReader
{
    private const string CHECKS_QUERY = <<<'GRAPHQL'
        query($owner:String!,$name:String!,$base:String!){repository(owner:$owner,name:$name){pullRequests(first:10,baseRefName:$base,orderBy:{field:UPDATED_AT,direction:DESC}){nodes{commits(last:1){nodes{commit{statusCheckRollup{contexts(first:100){nodes{__typename ... on CheckRun{name} ... on StatusContext{context}}}}}}}}}}}
        GRAPHQL;

    private const int RULES_PER_PAGE = 100;

    private const int MAX_RULE_PAGES = 10;

    private const int MAX_RECENT_CHECKS = 30;

    private const string LOUPE_CHECK_PREFIX = 'Loupe / ';

    public function __construct(
        private GitHubAppApi $api,
        private GitHubRepositoryInstallations $installations,
        private GitHubAppConfiguration $app,
    ) {
    }

    #[\Override]
    public function supports(string $forge): bool
    {
        return GitHubDelivery::FORGE === $forge;
    }

    #[\Override]
    public function read(ForgeRepository $repository): RepositorySettings
    {
        try {
            [$installationId, $path] = $this->installations->for($repository);
        } catch (GitHubInstallationUnavailable $e) {
            throw new RepositorySettingsUnreadable($e->reason, $e);
        }
        $appId = null !== $this->app->appId && ctype_digit($this->app->appId) && (int) $this->app->appId > 0 ? (int) $this->app->appId : null;
        if (null === $appId) {
            throw new RepositorySettingsUnreadable('app_not_configured');
        }

        try {
            return $this->readAll($installationId, $path, $appId);
        } catch (GitHubAppApiFailed $e) {
            throw new RepositorySettingsUnreadable('api_failed_'.$e->reason, $e);
        } catch (\UnexpectedValueException $e) {
            throw new RepositorySettingsUnreadable('malformed_body', $e);
        }
    }

    /**
     * @throws GitHubAppApiFailed
     * @throws \UnexpectedValueException
     * @throws RepositorySettingsUnreadable
     */
    private function readAll(int $installationId, string $path, int $appId): RepositorySettings
    {
        [$owner, $name] = explode('/', $path, 2) + [1 => ''];
        $repositoryPath = GitHubPullRequestInstallations::repositoryPath($path);

        $repo = $this->api->get($installationId, $repositoryPath);
        $default = $repo['default_branch'] ?? null;
        if (!\is_string($default) || '' === $default) {
            throw new RepositorySettingsUnreadable('default_branch_unreadable');
        }
        $switches = ['squash' => $repo['allow_squash_merge'] ?? null, 'merge' => $repo['allow_merge_commit'] ?? null, 'rebase' => $repo['allow_rebase_merge'] ?? null];
        if (array_any($switches, static fn (mixed $on): bool => !\is_bool($on))) {
            throw new RepositorySettingsUnreadable('merge_switches_unreadable');
        }

        $branchPath = $repositoryPath.'/branches/'.rawurlencode($default);
        $rules = $this->rules($installationId, $repositoryPath, $default);
        $rulesets = $this->rulesets($installationId, $repositoryPath, $rules);
        $branch = $this->api->get($installationId, $branchPath);
        $enabled = \is_array($branch['protection'] ?? null) ? ($branch['protection']['enabled'] ?? null) : null;
        if (!\is_bool($enabled)) {
            throw new RepositorySettingsUnreadable('branch_unreadable');
        }

        return new RepositorySettings(
            $default,
            array_keys(array_filter($switches)),
            $appId,
            new RepositoryRules(
                $rules->pullRequestRule,
                $rules->requiresReview,
                $rules->allowedMergeMethods,
                $rules->requiredChecks,
                $rules->approvalSources,
                $rules->forbidsForcePush,
                $rules->forbidsDeletion,
            ),
            $rulesets,
            $this->recentChecks($installationId, $owner, $name, $default),
            $enabled,
        );
    }

    /**
     * @throws GitHubAppApiFailed
     * @throws \UnexpectedValueException
     */
    private function rules(int $installationId, string $repositoryPath, string $branch): GitHubBranchRules
    {
        $all = [];
        for ($page = 1; $page <= self::MAX_RULE_PAGES; ++$page) {
            $items = $this->api->get($installationId, $repositoryPath.'/rules/branches/'.rawurlencode($branch), ['per_page' => self::RULES_PER_PAGE, 'page' => $page]);
            if (!array_is_list($items)) {
                throw new \UnexpectedValueException('The branch rules are not a list.');
            }
            $all = [...$all, ...$items];
            if (\count($items) < self::RULES_PER_PAGE) {
                return GitHubBranchRules::fromCompleteRules($all);
            }
        }

        // More rules than the pages hold: a partial list could read a missing rule as a gap.
        throw new \UnexpectedValueException('The branch rules run past the last page.');
    }

    /**
     * @return list<RepositoryRuleset>
     *
     * @throws GitHubAppApiFailed
     * @throws RepositorySettingsUnreadable
     */
    private function rulesets(int $installationId, string $repositoryPath, GitHubBranchRules $rules): array
    {
        $rulesets = [];
        foreach ($rules->rulesets as $id => $origin) {
            $name = null;
            try {
                $name = $this->api->get($installationId, $repositoryPath.'/rulesets/'.$id)['name'] ?? null;
                if (!\is_string($name) || '' === $name) {
                    throw new RepositorySettingsUnreadable('ruleset_unreadable');
                }
            } catch (GitHubAppApiFailed $e) {
                // An organization ruleset can hide its detail from the App. The id and the source still serve the guide.
                if ('http_status' !== $e->reason || $e->rateLimited || !\in_array($e->status, [403, 404], true)) {
                    throw $e;
                }
            }
            $rulesets[] = new RepositoryRuleset($id, $name, $origin['sourceType'], $origin['source'], array_values(array_unique($origin['rules'])));
        }

        return $rulesets;
    }

    /**
     * @return list<string>
     *
     * @throws GitHubAppApiFailed
     * @throws RepositorySettingsUnreadable
     */
    private function recentChecks(int $installationId, string $owner, string $name, string $base): array
    {
        $data = $this->api->graphql($installationId, self::CHECKS_QUERY, ['owner' => $owner, 'name' => $name, 'base' => $base]);
        $pullRequests = $data['repository']['pullRequests']['nodes'] ?? null;
        if (!\is_array($pullRequests)) {
            throw new RepositorySettingsUnreadable('checks_unreadable');
        }

        $names = [];
        foreach ($pullRequests as $pullRequest) {
            $contexts = \is_array($pullRequest) ? ($pullRequest['commits']['nodes'][0]['commit']['statusCheckRollup']['contexts']['nodes'] ?? []) : [];
            foreach (\is_array($contexts) ? $contexts : [] as $context) {
                $contextName = \is_array($context) ? ($context['name'] ?? $context['context'] ?? null) : null;
                if (\is_string($contextName) && '' !== $contextName && !str_starts_with($contextName, self::LOUPE_CHECK_PREFIX)) {
                    $names[$contextName] = true;
                }
            }
        }

        // A required check matches by its exact name, so the names stay as GitHub reports them.
        return \array_slice(array_map(strval(...), array_keys($names)), 0, self::MAX_RECENT_CHECKS);
    }
}
