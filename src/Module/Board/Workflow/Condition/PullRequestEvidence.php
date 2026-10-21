<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\MarkerSubject;
use App\Module\Workflow\Contract\MarkerSubjectType;
use App\Module\Workflow\Contract\PullRequestFacts;

/** The evidence of a pull request condition: the number of the pull request for the label, and the pull request with its head commit. */
final class PullRequestEvidence
{
    /** @param array<string, string|int> $params */
    public static function of(PullRequestFacts $pullRequest, array $params = []): Evidence
    {
        return new Evidence([...(null === $pullRequest->number ? [] : ['%number%' => $pullRequest->number]), ...$params], self::subject($pullRequest));
    }

    private static function subject(PullRequestFacts $pullRequest): ?MarkerSubject
    {
        if (null === $pullRequest->id || null === $pullRequest->headSha) {
            return null;
        }

        return new MarkerSubject(MarkerSubjectType::PullRequest, ['pullRequest' => $pullRequest->id->toRfc4122(), 'head' => $pullRequest->headSha]);
    }
}
