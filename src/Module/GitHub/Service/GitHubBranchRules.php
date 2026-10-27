<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgePullRequest;

/** The status check rules that apply to one branch, read from `GET /repos/{owner}/{repo}/rules/branches/{branch}`. */
final readonly class GitHubBranchRules
{
    /**
     * @param list<string>                                                               $requiredChecks
     * @param list<positive-int|null>                                                    $approvalSources     the App id of each entry that requires the approval check, null for an entry with no App
     * @param list<string>|null                                                          $allowedMergeMethods the methods that every pull request rule listing methods allows, null when no rule lists any
     * @param array<int, array{sourceType: string, source: string, rules: list<string>}> $rulesets            the ruleset behind each rule, by ruleset id
     */
    public function __construct(
        public array $requiredChecks,
        public bool $strict,
        public bool $requiresReview = false,
        public array $approvalSources = [],
        public bool $pullRequestRule = false,
        public ?array $allowedMergeMethods = null,
        public bool $forbidsForcePush = false,
        public bool $forbidsDeletion = false,
        public array $rulesets = [],
    ) {
    }

    public function requiresApprovalCheck(): bool
    {
        return \in_array(ForgePullRequest::APPROVAL_CHECK_NAME, $this->requiredChecks, true);
    }

    /**
     * @param array<mixed> $rules
     *
     * @throws \UnexpectedValueException when the answer is not a list of rules
     */
    public static function fromRules(array $rules): self
    {
        if (!array_is_list($rules)) {
            throw new \UnexpectedValueException('The branch rules are not a list.');
        }

        $checks = [];
        $strict = false;
        $requiresReview = false;
        $approvalSources = [];
        $pullRequestRule = false;
        $allowedMergeMethods = null;
        $forbidsForcePush = false;
        $forbidsDeletion = false;
        $rulesets = [];
        foreach ($rules as $rule) {
            if (!\is_array($rule)) {
                continue;
            }
            $type = $rule['type'] ?? null;
            $rulesetId = $rule['ruleset_id'] ?? null;
            if (\is_string($type) && \is_int($rulesetId)) {
                $rulesets[$rulesetId] ??= [
                    'sourceType' => \is_string($rule['ruleset_source_type'] ?? null) ? $rule['ruleset_source_type'] : '',
                    'source' => \is_string($rule['ruleset_source'] ?? null) ? $rule['ruleset_source'] : '',
                    'rules' => [],
                ];
                $rulesets[$rulesetId]['rules'][] = $type;
            }
            $forbidsForcePush = $forbidsForcePush || 'non_fast_forward' === $type;
            $forbidsDeletion = $forbidsDeletion || 'deletion' === $type;
            $pullRequestRule = $pullRequestRule || 'pull_request' === $type;
            if (!\is_array($rule['parameters'] ?? null)) {
                continue;
            }
            if ('pull_request' === $type) {
                $count = $rule['parameters']['required_approving_review_count'] ?? null;
                $requiresReview = $requiresReview || (\is_int($count) && $count >= 1);
                $methods = $rule['parameters']['allowed_merge_methods'] ?? null;
                if (\is_array($methods)) {
                    $methods = array_values(array_filter($methods, \is_string(...)));
                    $allowedMergeMethods = null === $allowedMergeMethods ? $methods : array_values(array_intersect($allowedMergeMethods, $methods));
                }

                continue;
            }
            if ('required_status_checks' !== $type) {
                continue;
            }

            $strict = $strict || true === ($rule['parameters']['strict_required_status_checks_policy'] ?? null);
            foreach (\is_array($rule['parameters']['required_status_checks'] ?? null) ? $rule['parameters']['required_status_checks'] : [] as $check) {
                $context = \is_array($check) ? ($check['context'] ?? null) : null;
                if (\is_string($context) && '' !== $context) {
                    $checks[$context] = true;
                    if (ForgePullRequest::APPROVAL_CHECK_NAME === $context) {
                        $integrationId = $check['integration_id'] ?? null;
                        $approvalSources[] = \is_int($integrationId) && $integrationId > 0 ? $integrationId : null;
                    }
                }
            }
        }

        return new self(array_map(strval(...), array_keys($checks)), $strict, $requiresReview, $approvalSources, $pullRequestRule, $allowedMergeMethods, $forbidsForcePush, $forbidsDeletion, $rulesets);
    }
}
