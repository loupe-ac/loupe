<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\Service;

use App\Module\Forge\Service\RepositorySuggestionCatalog;
use App\Module\Forge\Settings\RepositoryRules;
use App\Module\Forge\Settings\RepositorySettings;
use App\Module\Workflow\Contract\RepositorySuggestionKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RepositorySuggestionCatalogTest extends TestCase
{
    private const int APP = 123_456;

    /** @return iterable<string, array{RepositorySuggestionKey, RepositorySettings, list<string>}> */
    public static function cases(): iterable
    {
        $fits = self::rules(true, false, null, ['Loupe / Approval', 'ci'], [self::APP], true, true);

        yield 'approval-gate fits' => [RepositorySuggestionKey::ApprovalGate, self::settings($fits), []];
        yield 'approval-gate without a pull request rule' => [RepositorySuggestionKey::ApprovalGate, self::settings(self::rules(false, false, null, ['Loupe / Approval'], [self::APP])), ['no_pull_request_rule']];
        yield 'approval-gate without the required check' => [RepositorySuggestionKey::ApprovalGate, self::settings(self::rules(true, false, null, ['ci'], [])), ['approval_check_not_required']];
        yield 'approval-gate with an unpinned check' => [RepositorySuggestionKey::ApprovalGate, self::settings(self::rules(true, false, null, ['Loupe / Approval'], [null])), ['approval_check_unpinned']];
        yield 'approval-gate with another App behind the check' => [RepositorySuggestionKey::ApprovalGate, self::settings(self::rules(true, false, null, ['Loupe / Approval'], [self::APP, 999])), ['approval_check_unpinned']];
        yield 'approval-gate with no Loupe App id' => [RepositorySuggestionKey::ApprovalGate, self::settings(self::rules(true, false, null, ['Loupe / Approval'], [self::APP]), appId: null), ['approval_check_unpinned']];
        yield 'approval-gate with a required review' => [RepositorySuggestionKey::ApprovalGate, self::settings(self::rules(true, true, null, ['Loupe / Approval'], [self::APP])), ['review_required']];
        yield 'approval-gate with every gap' => [RepositorySuggestionKey::ApprovalGate, self::settings(self::rules(false, true, null, [], [])), ['no_pull_request_rule', 'approval_check_not_required', 'review_required']];

        yield 'ci-checks fits' => [RepositorySuggestionKey::CiChecks, self::settings($fits), []];
        yield 'ci-checks with only Loupe checks' => [RepositorySuggestionKey::CiChecks, self::settings(self::rules(true, false, null, ['Loupe / Approval'], [self::APP])), ['no_ci_check']];
        yield 'ci-checks with no checks' => [RepositorySuggestionKey::CiChecks, self::settings(self::rules(true, false, null, [], [])), ['no_ci_check']];

        yield 'branch-protection fits' => [RepositorySuggestionKey::BranchProtection, self::settings($fits), []];
        yield 'branch-protection allows a force push' => [RepositorySuggestionKey::BranchProtection, self::settings(self::rules(true, false, null, [], [], false, true)), ['force_push_allowed']];
        yield 'branch-protection allows a deletion' => [RepositorySuggestionKey::BranchProtection, self::settings(self::rules(true, false, null, [], [], true, false)), ['deletion_allowed']];
        yield 'branch-protection with neither rule' => [RepositorySuggestionKey::BranchProtection, self::settings(self::rules(true, false, null, [], [])), ['force_push_allowed', 'deletion_allowed']];

        yield 'merge-method fits with no rule listing methods' => [RepositorySuggestionKey::MergeMethod, self::settings($fits), []];
        yield 'merge-method fits on one shared method' => [RepositorySuggestionKey::MergeMethod, self::settings(self::rules(true, false, ['rebase', 'squash'], [], []), ['squash']), []];
        yield 'merge-method with no shared method' => [RepositorySuggestionKey::MergeMethod, self::settings(self::rules(true, false, ['merge'], [], []), ['squash', 'rebase']), ['no_merge_method']];
        yield 'merge-method with every switch off' => [RepositorySuggestionKey::MergeMethod, self::settings($fits, []), ['no_merge_method']];
        yield 'merge-method with a rule that lists no method' => [RepositorySuggestionKey::MergeMethod, self::settings(self::rules(true, false, [], [], [])), ['no_merge_method']];
    }

    /** @param list<string> $gaps */
    #[DataProvider('cases')]
    public function test_each_key_reports_its_gaps(RepositorySuggestionKey $key, RepositorySettings $settings, array $gaps): void
    {
        $evaluation = new RepositorySuggestionCatalog()->evaluate($key, $settings);

        self::assertSame($key, $evaluation->key);
        self::assertSame($gaps, $evaluation->gaps);
        self::assertSame([] === $gaps, $evaluation->fits());
    }

    public function test_the_catalog_covers_every_key(): void
    {
        $catalog = new RepositorySuggestionCatalog();
        foreach (RepositorySuggestionKey::cases() as $key) {
            self::assertSame($key, $catalog->evaluate($key, self::settings(self::rules(true, false, null, [], [])))->key);
        }
    }

    /**
     * @param list<string>|null $methods
     * @param list<string>      $checks
     * @param list<int|null>    $sources
     */
    private static function rules(bool $pullRequest, bool $review, ?array $methods, array $checks, array $sources, bool $force = false, bool $deletion = false): RepositoryRules
    {
        return new RepositoryRules($pullRequest, $review, $methods, $checks, $sources, $force, $deletion);
    }

    /** @param list<string> $mergeMethods */
    private static function settings(RepositoryRules $rules, array $mergeMethods = ['squash', 'merge', 'rebase'], ?int $appId = self::APP): RepositorySettings
    {
        return new RepositorySettings('main', $mergeMethods, $appId, $rules, [], [], false);
    }
}
