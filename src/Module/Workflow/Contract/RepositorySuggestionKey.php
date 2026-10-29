<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** The settings a workflow template can suggest for a repository. Each case needs code that reads the setting, so a template picks from this list and cannot add to it. */
enum RepositorySuggestionKey: string
{
    case ApprovalGate = 'approval-gate';
    case CiChecks = 'ci-checks';
    case BranchProtection = 'branch-protection';
    case MergeMethod = 'merge-method';
}
