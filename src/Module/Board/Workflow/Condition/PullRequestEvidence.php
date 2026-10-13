<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\PullRequestFacts;

/** The evidence of a pull request condition: a start time, and the number of the pull request for the label. */
final class PullRequestEvidence
{
    /** @param array<string, string|int> $params */
    public static function of(PullRequestFacts $pullRequest, ?\DateTimeImmutable $since, array $params = []): Evidence
    {
        return new Evidence($since, [...(null === $pullRequest->number ? [] : ['%number%' => $pullRequest->number]), ...$params]);
    }
}
