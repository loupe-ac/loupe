<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgePullRequest;

/** The status check rules that apply to one branch, read from `GET /repos/{owner}/{repo}/rules/branches/{branch}`. */
final readonly class GitHubBranchRules
{
    /**
     * @param list<string>            $requiredChecks
     * @param list<positive-int|null> $approvalSources     the App id of each entry that requires the approval check, null for an entry with no App
     * @param ?list<string>           $allowedMergeMethods the merge methods every pull request rule of the branch allows, or null when no rule limits them
     */
    public function __construct(
        public array $requiredChecks,
        public bool $strict,
        public bool $requiresReview = false,
        public array $approvalSources = [],
        public ?array $allowedMergeMethods = null,
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
        $allowedMergeMethods = null;
        foreach ($rules as $rule) {
            if (!\is_array($rule) || !\is_array($rule['parameters'] ?? null)) {
                continue;
            }
            if ('pull_request' === ($rule['type'] ?? null)) {
                $count = $rule['parameters']['required_approving_review_count'] ?? null;
                $requiresReview = $requiresReview || (\is_int($count) && $count >= 1);
                $allowed = $rule['parameters']['allowed_merge_methods'] ?? null;
                if (\is_array($allowed)) {
                    $allowed = array_values(array_filter($allowed, \is_string(...)));
                    $allowedMergeMethods = null === $allowedMergeMethods ? $allowed : array_values(array_intersect($allowedMergeMethods, $allowed));
                }

                continue;
            }
            if ('required_status_checks' !== ($rule['type'] ?? null)) {
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

        return new self(array_map(strval(...), array_keys($checks)), $strict, $requiresReview, $approvalSources, $allowedMergeMethods);
    }
}
