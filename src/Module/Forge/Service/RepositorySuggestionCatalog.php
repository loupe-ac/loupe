<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Settings\RepositorySettings;
use App\Module\Workflow\Contract\RepositorySuggestionKey;

/** Says what each suggestion key checks on the default branch of a repository. */
final readonly class RepositorySuggestionCatalog
{
    public const string GAP_NO_PULL_REQUEST_RULE = 'no_pull_request_rule';
    public const string GAP_APPROVAL_CHECK_NOT_REQUIRED = 'approval_check_not_required';
    public const string GAP_APPROVAL_CHECK_UNPINNED = 'approval_check_unpinned';
    public const string GAP_REVIEW_REQUIRED = 'review_required';
    public const string GAP_NO_CI_CHECK = 'no_ci_check';
    public const string GAP_FORCE_PUSH_ALLOWED = 'force_push_allowed';
    public const string GAP_DELETION_ALLOWED = 'deletion_allowed';
    public const string GAP_NO_MERGE_METHOD = 'no_merge_method';
    public const string GAP_CLASSIC_PROTECTION = 'classic_protection';

    private const string LOUPE_CHECK_PREFIX = 'Loupe / ';

    public function evaluate(RepositorySuggestionKey $key, RepositorySettings $settings): RepositorySuggestionEvaluation
    {
        return new RepositorySuggestionEvaluation($key, match ($key) {
            RepositorySuggestionKey::ApprovalGate => $this->approvalGate($settings),
            RepositorySuggestionKey::CiChecks => $this->ciChecks($settings),
            RepositorySuggestionKey::BranchProtection => $this->branchProtection($settings),
            RepositorySuggestionKey::MergeMethod => $this->mergeMethod($settings),
        });
    }

    /** @return list<string> */
    private function approvalGate(RepositorySettings $settings): array
    {
        $rules = $settings->rules;
        $gaps = [];
        if (!$rules->pullRequestRule) {
            $gaps[] = self::GAP_NO_PULL_REQUEST_RULE;
        }
        if (!\in_array(ForgePullRequest::APPROVAL_CHECK_NAME, $rules->requiredChecks, true)) {
            $gaps[] = self::GAP_APPROVAL_CHECK_NOT_REQUIRED;
        } elseif (null === $settings->loupeAppId || array_any($rules->approvalSources, static fn (?int $source): bool => $source !== $settings->loupeAppId)) {
            $gaps[] = self::GAP_APPROVAL_CHECK_UNPINNED;
        }
        if ($rules->requiresReview) {
            $gaps[] = self::GAP_REVIEW_REQUIRED;
        }

        return $gaps;
    }

    /** @return list<string> */
    private function ciChecks(RepositorySettings $settings): array
    {
        return array_any($settings->rules->requiredChecks, static fn (string $check): bool => !str_starts_with($check, self::LOUPE_CHECK_PREFIX)) ? [] : [self::GAP_NO_CI_CHECK];
    }

    /** @return list<string> */
    private function branchProtection(RepositorySettings $settings): array
    {
        $gaps = [];
        if (!$settings->rules->forbidsForcePush) {
            $gaps[] = self::GAP_FORCE_PUSH_ALLOWED;
        }
        if (!$settings->rules->forbidsDeletion) {
            $gaps[] = self::GAP_DELETION_ALLOWED;
        }

        return $gaps;
    }

    /** @return list<string> */
    private function mergeMethod(RepositorySettings $settings): array
    {
        return [] === $settings->usableMergeMethods() ? [self::GAP_NO_MERGE_METHOD] : [];
    }
}
