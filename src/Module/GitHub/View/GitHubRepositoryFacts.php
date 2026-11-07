<?php

declare(strict_types=1);

namespace App\Module\GitHub\View;

/** What `loupe init` needs to know about one GitHub repository of a project. */
final readonly class GitHubRepositoryFacts
{
    public const string RULES_OK = 'ok';
    public const string RULES_PLAN_LIMITED = 'plan-limited';
    public const string RULES_REFUSED = 'refused';

    /**
     * @param bool    $appInstalled        whether a live installation of the Loupe GitHub App delivers this repository, so Loupe can post checks on it
     * @param ?string $defaultBranch       null until a read succeeded
     * @param ?string $rulesRead           ok, plan-limited or refused; null when the app has not read the repository yet
     * @param ?bool   $reviewRequired      whether the rules of the default branch require an approving review; false when the plan has no rules; null when unknown
     * @param ?string $mergeApproval       the resolved merge-approval setting, null while unset
     * @param string  $mergeApprovalSource where that value came from, such as file, stored or unset
     */
    public function __construct(
        public string $path,
        public bool $appInstalled,
        public ?string $defaultBranch,
        public ?string $rulesRead,
        public ?bool $reviewRequired,
        public ?string $mergeApproval,
        public string $mergeApprovalSource,
    ) {
    }
}
