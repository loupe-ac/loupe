<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\GitHub\Service\GitHubBranchRules;
use PHPUnit\Framework\TestCase;

final class GitHubBranchRulesTest extends TestCase
{
    public function test_a_review_rule_and_the_merge_methods_are_read(): void
    {
        $rules = GitHubBranchRules::fromRules([
            ['type' => 'pull_request', 'parameters' => ['required_approving_review_count' => 1, 'allowed_merge_methods' => ['merge', 'squash']], 'ruleset_id' => 1, 'ruleset_source_type' => 'Repository', 'ruleset_source' => 'a/b'],
            ['type' => 'pull_request', 'parameters' => ['required_approving_review_count' => 0, 'allowed_merge_methods' => ['squash', 'rebase']], 'ruleset_id' => 2, 'ruleset_source_type' => 'Organization', 'ruleset_source' => 'a'],
        ]);

        self::assertTrue($rules->pullRequestRule);
        self::assertTrue($rules->requiresReview);
        self::assertSame(['squash'], $rules->allowedMergeMethods);
        self::assertSame([
            1 => ['sourceType' => 'Repository', 'source' => 'a/b', 'rules' => ['pull_request']],
            2 => ['sourceType' => 'Organization', 'source' => 'a', 'rules' => ['pull_request']],
        ], $rules->rulesets);
    }

    public function test_a_pull_request_rule_that_lists_no_method_leaves_the_methods_open(): void
    {
        $rules = GitHubBranchRules::fromRules([['type' => 'pull_request', 'parameters' => ['required_approving_review_count' => 0]]]);

        self::assertTrue($rules->pullRequestRule);
        self::assertFalse($rules->requiresReview);
        self::assertNull($rules->allowedMergeMethods);
    }

    public function test_the_rules_without_parameters_are_read(): void
    {
        $rules = GitHubBranchRules::fromRules([['type' => 'non_fast_forward'], ['type' => 'deletion', 'ruleset_id' => 3]]);

        self::assertTrue($rules->forbidsForcePush);
        self::assertTrue($rules->forbidsDeletion);
        self::assertFalse($rules->pullRequestRule);
        self::assertSame([3 => ['sourceType' => '', 'source' => '', 'rules' => ['deletion']]], $rules->rulesets);
    }

    public function test_no_rule_forbids_nothing(): void
    {
        $rules = GitHubBranchRules::fromRules([['type' => 'creation']]);

        self::assertFalse($rules->forbidsForcePush);
        self::assertFalse($rules->forbidsDeletion);
        self::assertFalse($rules->pullRequestRule);
    }
}
