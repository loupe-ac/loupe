<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\FailedCheckNames;
use App\Module\Forge\Settings\RepositorySettings;

/**
 * Builds the ruleset that the guide asks the owner to import on GitHub. A rule
 * is in the file only when the template suggests its key, so a template that
 * leaves a setting out never gets a rule for it.
 */
final readonly class GitHubRulesetFile
{
    public const string APPROVAL_GATE = 'approval-gate';
    public const string CI_CHECKS = 'ci-checks';
    public const string BRANCH_PROTECTION = 'branch-protection';

    public const string NAME = 'Loupe';
    public const string FILE_NAME = 'loupe-ruleset.json';

    /**
     * @param list<string> $keys the suggestion keys of the stored check
     *
     * @return array<string, mixed>|null null when no rule would be in the file
     */
    public function build(?RepositorySettings $settings, array $keys): ?array
    {
        $rules = [];
        $approval = \in_array(self::APPROVAL_GATE, $keys, true);
        if ($approval) {
            $rules[] = [
                'type' => 'pull_request',
                'parameters' => [
                    'dismiss_stale_reviews_on_push' => false,
                    'require_code_owner_review' => false,
                    'require_last_push_approval' => false,
                    'required_approving_review_count' => 0,
                    'required_review_thread_resolution' => false,
                ],
            ];
        }

        $checks = [];
        if ($approval) {
            $checks[] = ['context' => ForgePullRequest::APPROVAL_CHECK_NAME] + (null === $settings?->loupeAppId ? [] : ['integration_id' => $settings->loupeAppId]);
        }
        foreach ($this->checkNames($settings, $keys) as $name) {
            $checks[] = ['context' => $name];
        }
        if ([] !== $checks) {
            $rules[] = [
                'type' => 'required_status_checks',
                'parameters' => ['required_status_checks' => $checks, 'strict_required_status_checks_policy' => false],
            ];
        }

        if (\in_array(self::BRANCH_PROTECTION, $keys, true)) {
            $rules[] = ['type' => 'non_fast_forward'];
            $rules[] = ['type' => 'deletion'];
        }

        if ([] === $rules) {
            return null;
        }

        return [
            'name' => self::NAME,
            'target' => 'branch',
            'enforcement' => 'active',
            'conditions' => ['ref_name' => ['include' => ['~DEFAULT_BRANCH'], 'exclude' => []]],
            'rules' => $rules,
            'bypass_actors' => [],
        ];
    }

    /** @param list<string> $keys */
    public function json(?RepositorySettings $settings, array $keys): ?string
    {
        $file = $this->build($settings, $keys);

        return null === $file ? null : json_encode($file, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string> the CI check names that the file requires, empty when the template suggests no CI check
     */
    public function checkNames(?RepositorySettings $settings, array $keys): array
    {
        if (!\in_array(self::CI_CHECKS, $keys, true) || null === $settings) {
            return [];
        }

        return array_values(array_unique(FailedCheckNames::clean($settings->recentChecks)));
    }
}
