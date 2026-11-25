<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** Why a condition cannot be waited for. */
enum WaitConditionRefusal: string
{
    case UnknownCondition = 'unknown-condition';
    case InvalidParameters = 'invalid-parameters';
    case PullRequestRequired = 'pull-request-required';
    case PullRequestNotLinked = 'pull-request-not-linked';
    case PullRequestNotOpen = 'pull-request-not-open';
    case Unreadable = 'unreadable';
}
