<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Forge\Settings\RepositoryRules;
use App\Module\Forge\Settings\RepositorySettings;
use App\Module\GitHub\Service\GitHubRulesetFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GitHubRulesetFileTest extends TestCase
{
    private const array ALL_KEYS = ['approval-gate', 'ci-checks', 'branch-protection'];

    public function test_the_file_is_a_branch_ruleset_for_the_default_branch(): void
    {
        $file = new GitHubRulesetFile()->build($this->settings(), self::ALL_KEYS);

        self::assertNotNull($file);
        self::assertSame('Loupe', $file['name']);
        self::assertSame('branch', $file['target']);
        self::assertSame('active', $file['enforcement']);
        self::assertSame(['include' => ['~DEFAULT_BRANCH'], 'exclude' => []], $file['conditions']['ref_name']);
        self::assertSame([], $file['bypass_actors']);
    }

    public function test_the_pull_request_rule_asks_for_no_approving_review_and_names_every_field_that_github_requires(): void
    {
        $file = new GitHubRulesetFile()->build($this->settings(), self::ALL_KEYS);

        $rule = $this->rule($file, 'pull_request');
        self::assertSame(0, $rule['parameters']['required_approving_review_count']);
        self::assertSame(
            ['dismiss_stale_reviews_on_push', 'require_code_owner_review', 'require_last_push_approval', 'required_approving_review_count', 'required_review_thread_resolution'],
            $this->sorted(array_keys($rule['parameters'])),
        );
    }

    public function test_the_approval_check_is_pinned_to_the_stored_loupe_app(): void
    {
        $file = new GitHubRulesetFile()->build($this->settings(appId: 4242), self::ALL_KEYS);

        $checks = $this->rule($file, 'required_status_checks')['parameters']['required_status_checks'];
        self::assertSame(['context' => 'Loupe / Approval', 'integration_id' => 4242], $checks[0]);
        self::assertSame(['context' => 'lint'], $checks[1]);
        self::assertFalse($this->rule($file, 'required_status_checks')['parameters']['strict_required_status_checks_policy']);
    }

    public function test_without_an_app_id_the_approval_check_has_no_pin(): void
    {
        $file = new GitHubRulesetFile()->build($this->settings(appId: null), self::ALL_KEYS);

        $checks = $this->rule($file, 'required_status_checks')['parameters']['required_status_checks'];
        self::assertSame(['context' => 'Loupe / Approval'], $checks[0]);
    }

    public function test_the_recent_check_names_are_cleaned_capped_and_listed_once(): void
    {
        $long = str_repeat('x', 250);
        $file = new GitHubRulesetFile()->build($this->settings(recent: ['lint', 'lint', "bad\"{name}\n", $long]), self::ALL_KEYS);

        $names = array_column($this->rule($file, 'required_status_checks')['parameters']['required_status_checks'], 'context');
        self::assertSame(['Loupe / Approval', 'lint', 'badname', str_repeat('x', 200)], $names);
    }

    public function test_a_template_without_ci_checks_adds_no_ci_check_to_the_file(): void
    {
        $file = new GitHubRulesetFile()->build($this->settings(), ['approval-gate', 'branch-protection']);

        $names = array_column($this->rule($file, 'required_status_checks')['parameters']['required_status_checks'], 'context');
        self::assertSame(['Loupe / Approval'], $names);
    }

    public function test_a_template_without_branch_protection_adds_neither_rule(): void
    {
        $file = new GitHubRulesetFile()->build($this->settings(), ['approval-gate']);

        self::assertNull($this->findRule($file, 'non_fast_forward'));
        self::assertNull($this->findRule($file, 'deletion'));
    }

    public function test_the_branch_protection_rules_carry_no_parameters(): void
    {
        $file = new GitHubRulesetFile()->build($this->settings(), self::ALL_KEYS);

        self::assertSame(['type' => 'non_fast_forward'], $this->rule($file, 'non_fast_forward'));
        self::assertSame(['type' => 'deletion'], $this->rule($file, 'deletion'));
    }

    public function test_a_template_without_approval_gate_adds_no_approval_rule(): void
    {
        $file = new GitHubRulesetFile()->build($this->settings(), ['ci-checks']);

        self::assertNull($this->findRule($file, 'pull_request'));
        self::assertSame([['context' => 'lint']], $this->rule($file, 'required_status_checks')['parameters']['required_status_checks']);
    }

    /**
     * @param list<string> $keys
     * @param list<string> $recent
     */
    #[DataProvider('nothingToImport')]
    public function test_a_check_with_nothing_to_import_gives_no_file(array $keys, array $recent): void
    {
        self::assertNull(new GitHubRulesetFile()->build($this->settings(recent: $recent), $keys));
    }

    /** @return iterable<string, array{list<string>, list<string>}> */
    public static function nothingToImport(): iterable
    {
        yield 'no key' => [[], ['lint']];
        yield 'ci checks with no recent check' => [['ci-checks'], []];
        yield 'a key that names no rule' => [['merge-method'], ['lint']];
    }

    public function test_the_json_is_valid_and_keeps_a_name_with_markup_as_plain_text(): void
    {
        $json = new GitHubRulesetFile()->json($this->settings(recent: ['<script>alert(1)</script>']), self::ALL_KEYS);

        self::assertNotNull($json);
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('<script>alert(1)</script>', $this->rule($decoded, 'required_status_checks')['parameters']['required_status_checks'][1]['context']);
        self::assertStringContainsString('"integration_id": 4242', $json);
    }

    /**
     * @param array<string, mixed>|null $file
     *
     * @return array<string, mixed>
     */
    private function rule(?array $file, string $type): array
    {
        $rule = $this->findRule($file, $type);
        self::assertNotNull($rule, 'The file has a '.$type.' rule.');

        return $rule;
    }

    /**
     * @param array<string, mixed>|null $file
     *
     * @return array<string, mixed>|null
     */
    private function findRule(?array $file, string $type): ?array
    {
        foreach ($file['rules'] ?? [] as $rule) {
            if ($type === $rule['type']) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param list<string|int> $values
     *
     * @return list<string|int>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /** @param list<string> $recent */
    private function settings(?int $appId = 4242, array $recent = ['lint']): RepositorySettings
    {
        return new RepositorySettings('main', ['squash'], $appId, new RepositoryRules(false, false, null, [], [], false, false), [], $recent, false);
    }
}
