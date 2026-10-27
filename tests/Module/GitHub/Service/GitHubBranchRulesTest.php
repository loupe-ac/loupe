<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\GitHub\Service\GitHubBranchRules;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @param list<mixed> $rules */
    #[DataProvider('incompleteRules')]
    public function test_an_incomplete_rule_is_refused_by_the_complete_read(array $rules): void
    {
        $this->expectException(\UnexpectedValueException::class);

        GitHubBranchRules::fromCompleteRules($rules);
    }

    /** @return iterable<string, array{list<mixed>}> */
    public static function incompleteRules(): iterable
    {
        $checks = static fn (mixed $entries): array => ['type' => 'required_status_checks', 'parameters' => ['required_status_checks' => $entries]];

        yield 'a rule that is not an object' => [['x']];
        yield 'a rule with no type' => [[['ruleset_id' => 1]]];
        yield 'a pull request rule with no parameters' => [[['type' => 'pull_request']]];
        yield 'a pull request rule with no review count' => [[['type' => 'pull_request', 'parameters' => []]]];
        yield 'a pull request rule with a text review count' => [[['type' => 'pull_request', 'parameters' => ['required_approving_review_count' => '1']]]];
        yield 'a pull request rule with malformed methods' => [[['type' => 'pull_request', 'parameters' => ['required_approving_review_count' => 1, 'allowed_merge_methods' => [1]]]]];
        yield 'a status check rule with no parameters' => [[['type' => 'required_status_checks']]];
        yield 'a status check rule with no check list' => [[['type' => 'required_status_checks', 'parameters' => []]]];
        yield 'a check that is not an object' => [[$checks(['lint'])]];
        yield 'a check with no context' => [[$checks([['integration_id' => 1]])]];
        yield 'a check with an empty context' => [[$checks([['context' => '']])]];
        yield 'a check with a text integration' => [[$checks([['context' => 'lint', 'integration_id' => 'x']])]];
    }

    public function test_complete_rules_read_as_the_lenient_read_does(): void
    {
        $rules = [
            ['type' => 'pull_request', 'parameters' => ['required_approving_review_count' => 1]],
            ['type' => 'required_status_checks', 'parameters' => ['required_status_checks' => [['context' => 'lint'], ['context' => 'test', 'integration_id' => 5]]]],
            ['type' => 'deletion'],
        ];

        self::assertEquals(GitHubBranchRules::fromRules($rules), GitHubBranchRules::fromCompleteRules($rules));
    }
}
