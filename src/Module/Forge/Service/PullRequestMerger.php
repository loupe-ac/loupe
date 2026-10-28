<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Merges a pull request on one forge. A forge module implements it. */
#[AutoconfigureTag('app.pull_request_merger')]
interface PullRequestMerger
{
    public const array METHODS = ['merge', 'squash', 'rebase'];

    /** @param string $forge the forge's slug, such as `github` */
    public function supports(string $forge): bool;

    /**
     * Merges with the first method of the list that the repository and its rules for the base branch allow.
     *
     * @param list<string> $methods         the methods to try in order, each one of METHODS
     * @param string       $expectedHeadSha the forge refuses the merge when the branch no longer points here
     *
     * @throws \InvalidArgumentException when a method is not one of METHODS
     * @throws PullRequestWriteFailed    when the forge does not take the merge, or allows none of the methods
     */
    public function merge(ForgePullRequest $pullRequest, array $methods, string $expectedHeadSha): void;
}
