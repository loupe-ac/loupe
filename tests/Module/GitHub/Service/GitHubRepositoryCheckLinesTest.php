<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositoryCheck;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Service\RepositorySuggestionCatalog;
use App\Module\Forge\Settings\RepositoryRules;
use App\Module\Forge\Settings\RepositoryRuleset;
use App\Module\Forge\Settings\RepositorySettings;
use App\Module\GitHub\Service\GitHubRepositoryCheckLines;
use App\Module\Workflow\Contract\ResolvedSetting;
use App\Module\Workflow\Contract\SettingSource;
use App\Module\Workflow\Contract\WorkflowSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

final class GitHubRepositoryCheckLinesTest extends TestCase
{
    private const string P = 'github.repositories.check.';

    public function test_a_blocking_gap_is_a_failed_line_that_names_the_branch(): void
    {
        $check = $this->check([$this->item('approval-gate', 'blocking', [RepositorySuggestionCatalog::GAP_APPROVAL_CHECK_NOT_REQUIRED])], $this->settings());

        $lines = $this->lines()->forCheck($check);

        self::assertCount(1, $lines);
        self::assertSame('failed', $lines[0]->modifier);
        self::assertSame(self::P.'state.blocking', $lines[0]->stateKey);
        self::assertSame(self::P.'approval-gate.approval_check_not_required.title', $lines[0]->titleKey);
        self::assertSame(['%branch%' => 'main'], $lines[0]->titleParameters);
        self::assertSame(self::P.'approval-gate.approval_check_not_required.detail', $lines[0]->detailKey);
    }

    public function test_each_warning_of_the_workflow_file_read_is_a_warning_line_after_the_gap_lines(): void
    {
        $check = $this->check([$this->item('branch-protection', 'warning', [RepositorySuggestionCatalog::GAP_FORCE_PUSH_ALLOWED])], $this->settings());
        $check->repository->fileWarnings = ['The setting "x" is not known. Loupe ignores it.', 'The setting "merge-approval" has a value that is not allowed.'];

        $lines = $this->lines()->forCheck($check);

        self::assertCount(3, $lines);
        self::assertSame(self::P.'workflow_file.title', $lines[1]->titleKey);
        self::assertSame(self::P.'state.warning', $lines[1]->stateKey);
        self::assertSame('pending', $lines[1]->modifier);
        self::assertSame(['%message%' => 'The setting "x" is not known. Loupe ignores it.'], $lines[1]->detailParameters);
        self::assertSame(self::P.'workflow_file.detail', $lines[2]->detailKey);
    }

    public function test_a_warning_gap_is_a_pending_line_and_each_gap_gets_its_own_line(): void
    {
        $check = $this->check([$this->item('branch-protection', 'warning', [RepositorySuggestionCatalog::GAP_FORCE_PUSH_ALLOWED, RepositorySuggestionCatalog::GAP_DELETION_ALLOWED])], $this->settings());

        $lines = $this->lines()->forCheck($check);

        self::assertSame(['pending', 'pending'], array_map(static fn ($line): string => $line->modifier, $lines));
        self::assertSame(self::P.'state.warning', $lines[0]->stateKey);
        self::assertSame(self::P.'branch-protection.force_push_allowed.title', $lines[0]->titleKey);
        self::assertSame(self::P.'branch-protection.deletion_allowed.title', $lines[1]->titleKey);
    }

    public function test_the_review_gap_names_the_rulesets_that_hold_a_pull_request_rule(): void
    {
        $settings = $this->settings(rulesets: [
            new RepositoryRuleset(1, 'main protection', 'Repository', 'acme/app', ['pull_request']),
            new RepositoryRuleset(2, 'tags', 'Repository', 'acme/app', ['deletion']),
        ]);
        $check = $this->check([$this->item('approval-gate', 'blocking', [RepositorySuggestionCatalog::GAP_REVIEW_REQUIRED])], $settings);

        $line = $this->lines()->forCheck($check)[0];

        self::assertSame(self::P.'approval-gate.review_required.detail_named', $line->detailKey);
        self::assertSame('main protection', $line->detailParameters['%rulesets%']);
    }

    public function test_a_ruleset_that_the_forge_hid_is_not_named(): void
    {
        $settings = $this->settings(rulesets: [new RepositoryRuleset(1, null, 'Repository', 'acme/app', ['pull_request'])]);
        $check = $this->check([$this->item('approval-gate', 'blocking', [RepositorySuggestionCatalog::GAP_REVIEW_REQUIRED])], $settings);

        self::assertSame(self::P.'approval-gate.review_required.detail', $this->lines()->forCheck($check)[0]->detailKey);
    }

    public function test_the_missing_ci_check_lists_the_recent_checks_when_the_settings_hold_them(): void
    {
        $with = $this->check([$this->item('ci-checks', 'warning', [RepositorySuggestionCatalog::GAP_NO_CI_CHECK])], $this->settings(recentChecks: ['lint', 'phpunit']));
        $without = $this->check([$this->item('ci-checks', 'warning', [RepositorySuggestionCatalog::GAP_NO_CI_CHECK])], $this->settings());

        $seen = $this->lines()->forCheck($with)[0];
        self::assertSame(self::P.'ci-checks.no_ci_check.detail_seen', $seen->detailKey);
        self::assertSame('lint, phpunit', $seen->detailParameters['%checks%']);
        self::assertSame(self::P.'ci-checks.no_ci_check.detail', $this->lines()->forCheck($without)[0]->detailKey);
    }

    public function test_the_merge_method_gap_names_what_the_repository_and_its_rulesets_allow(): void
    {
        $settings = $this->settings(mergeMethods: ['merge', 'squash'], allowedMergeMethods: ['rebase']);
        $check = $this->check([$this->item('merge-method', 'blocking', [RepositorySuggestionCatalog::GAP_NO_MERGE_METHOD])], $settings);

        $line = $this->lines()->forCheck($check)[0];

        self::assertSame(self::P.'merge-method.no_merge_method.detail_named', $line->detailKey);
        self::assertSame(self::P.'method.squash, '.self::P.'method.merge', $line->detailParameters['%repository%']);
        self::assertSame(self::P.'method.rebase', $line->detailParameters['%rulesets%']);
    }

    public function test_the_merge_method_gap_says_when_a_side_allows_nothing_or_sets_no_limit(): void
    {
        $settings = $this->settings(mergeMethods: []);
        $check = $this->check([$this->item('merge-method', 'blocking', [RepositorySuggestionCatalog::GAP_NO_MERGE_METHOD])], $settings);

        $line = $this->lines()->forCheck($check)[0];

        self::assertSame(self::P.'method.none', $line->detailParameters['%repository%']);
        self::assertSame(self::P.'method.any', $line->detailParameters['%rulesets%']);
        self::assertSame(
            self::P.'merge-method.no_merge_method.detail',
            $this->lines()->forCheck($this->check([$this->item('merge-method', 'blocking', [RepositorySuggestionCatalog::GAP_NO_MERGE_METHOD])], null))[0]->detailKey,
        );
    }

    public function test_a_fitting_item_is_an_ok_line(): void
    {
        $settings = $this->settings(requiredChecks: ['Loupe / Approval', 'lint', 'phpunit'], mergeMethods: ['merge', 'squash']);
        $check = $this->check([
            $this->item('ci-checks', 'warning', []),
            $this->item('merge-method', 'blocking', []),
        ], $settings);

        [$ci, $merge] = $this->lines()->forCheck($check);

        self::assertSame('ok', $ci->modifier);
        self::assertSame(self::P.'state.fits', $ci->stateKey);
        self::assertSame(self::P.'ci-checks.fits.detail', $ci->detailKey);
        self::assertSame('lint, phpunit', $ci->detailParameters['%checks%']);
        self::assertSame(self::P.'merge-method.fits.detail', $merge->detailKey);
        self::assertSame(self::P.'method.squash', $merge->detailParameters['%method%']);
    }

    public function test_a_check_with_no_stored_settings_says_less(): void
    {
        $check = $this->check([
            $this->item('ci-checks', 'warning', []),
            $this->item('merge-method', 'blocking', []),
            $this->item('approval-gate', 'blocking', [RepositorySuggestionCatalog::GAP_NO_PULL_REQUEST_RULE]),
        ], null);

        [$ci, $merge, $gate] = $this->lines()->forCheck($check);

        self::assertSame(self::P.'ci-checks.fits.detail_unnamed', $ci->detailKey);
        self::assertSame(self::P.'merge-method.fits.detail_unnamed', $merge->detailKey);
        self::assertSame(self::P.'default_branch', $gate->titleParameters['%branch%']);
    }

    public function test_the_classic_protection_row_is_a_warning(): void
    {
        $check = $this->check([$this->item(ForgeRepositoryCheck::KEY_CLASSIC_PROTECTION, 'warning', [RepositorySuggestionCatalog::GAP_CLASSIC_PROTECTION])], $this->settings());

        $line = $this->lines()->forCheck($check)[0];

        self::assertSame('pending', $line->modifier);
        self::assertSame(self::P.'classic-protection.classic_protection.title', $line->titleKey);
    }

    public function test_rules_the_plan_cannot_hold_add_a_line_with_the_github_message(): void
    {
        $settings = new RepositorySettings('main', ['squash'], 15368, new RepositoryRules(false, false, null, [], [], false, false), [], [], false, 'Upgrade to GitHub Pro.');
        $check = $this->check([$this->item('ci-checks', 'warning', [RepositorySuggestionCatalog::GAP_NO_CI_CHECK])], $settings);

        $lines = $this->lines()->forCheck($check);

        self::assertCount(2, $lines);
        self::assertSame(self::P.'rules_unavailable.title', $lines[0]->titleKey);
        self::assertSame('pending', $lines[0]->modifier);
        self::assertSame(['%message%' => 'Upgrade to GitHub Pro.'], $lines[0]->detailParameters);
    }

    public function test_a_gap_that_does_not_belong_to_its_key_gets_the_generic_line(): void
    {
        $check = $this->check([$this->item('ci-checks', 'warning', ['no_such_gap'])], $this->settings());

        self::assertSame(self::P.'unknown.title', $this->lines()->forCheck($check)[0]->titleKey);
    }

    public function test_a_project_setting_conflict_is_a_warning_line_on_the_check(): void
    {
        $check = $this->check([$this->item('branch-protection', 'warning', [RepositorySuggestionCatalog::GAP_FORCE_PUSH_ALLOWED])], $this->settings());
        new \ReflectionProperty($check->repository->project, 'id')->setValue($check->repository->project, Uuid::v7());
        new \ReflectionProperty($check->repository, 'id')->setValue($check->repository, Uuid::v7());
        $message = 'The setting "epic-branch-pattern" has different values in the repositories of the project (acme/app sets a/{number}; acme/other sets b/{number}).';

        $lines = $this->lines([$message])->forCheck($check);

        self::assertCount(2, $lines);
        self::assertSame(self::P.'workflow_file.title', $lines[1]->titleKey);
        self::assertSame(['%message%' => $message], $lines[1]->detailParameters);
    }

    private const string REFUSED = 'Upgrade to GitHub Pro';

    public function test_a_plan_refusal_keeps_only_the_paid_plan_warning(): void
    {
        $check = $this->check([$this->item('merge-method', 'blocking', [])], $this->settings(rulesUnavailable: self::REFUSED));

        $lines = $this->lines()->forCheck($check);

        self::assertSame([self::P.'rules_unavailable.title', self::P.'merge-method.fits.title'], array_map(static fn ($line): string => $line->titleKey, $lines));
    }

    public function test_a_capable_repository_that_sets_the_list_gets_a_warning_to_move_it_to_a_ruleset(): void
    {
        $check = $this->check([], $this->settings());

        $lines = $this->lines(requiredChecks: new ResolvedSetting(RepositorySettings::REQUIRED_CHECKS_SETTING, ['lint'], SettingSource::File))->forCheck($check);

        self::assertCount(1, $lines);
        self::assertSame(self::P.'required_checks.ignored.title', $lines[0]->titleKey);
        self::assertSame('pending', $lines[0]->modifier);
    }

    public function test_a_refused_repository_with_a_list_gets_no_ignored_warning(): void
    {
        $check = $this->check([], $this->settings(recentChecks: ['lint'], rulesUnavailable: self::REFUSED));

        $lines = $this->lines(requiredChecks: new ResolvedSetting(RepositorySettings::REQUIRED_CHECKS_SETTING, ['lint'], SettingSource::File))->forCheck($check);

        self::assertSame([self::P.'rules_unavailable.title'], array_map(static fn ($line): string => $line->titleKey, $lines));
    }

    public function test_a_required_check_of_a_ruleset_that_no_recent_pull_request_ran_is_a_warning_with_its_source(): void
    {
        $check = $this->check([], $this->settings(recentChecks: ['lint'], requiredChecks: ['lint', 'gone', 'Loupe / Approval']));

        $lines = $this->lines()->forCheck($check);

        self::assertCount(1, $lines);
        self::assertSame(self::P.'required_checks.never_ran.title', $lines[0]->titleKey);
        self::assertSame('gone', $lines[0]->detailParameters['%checks%']);
        self::assertSame(self::P.'required_checks.source.ruleset', $lines[0]->detailParameters['%source%']);
    }

    public function test_a_listed_check_that_no_recent_pull_request_ran_names_the_file(): void
    {
        $check = $this->check([], $this->settings(recentChecks: ['lint'], rulesUnavailable: self::REFUSED));

        $lines = $this->lines(requiredChecks: new ResolvedSetting(RepositorySettings::REQUIRED_CHECKS_SETTING, ['lint', 'gone'], SettingSource::File))->forCheck($check);

        self::assertCount(2, $lines);
        self::assertSame(self::P.'required_checks.never_ran.title', $lines[1]->titleKey);
        self::assertSame('gone', $lines[1]->detailParameters['%checks%']);
        self::assertSame(self::P.'required_checks.source.file', $lines[1]->detailParameters['%source%']);
    }

    public function test_a_recent_check_that_the_list_omits_is_a_warning_that_names_it(): void
    {
        $check = $this->check([], $this->settings(recentChecks: ['lint', 'extra'], requiredChecks: ['lint']));

        $lines = $this->lines()->forCheck($check);

        self::assertCount(1, $lines);
        self::assertSame(self::P.'required_checks.unlisted.title', $lines[0]->titleKey);
        self::assertSame('extra', $lines[0]->detailParameters['%checks%']);
    }

    public function test_no_drift_warning_without_recent_checks(): void
    {
        $check = $this->check([], $this->settings(recentChecks: [], requiredChecks: ['lint']));

        self::assertSame([], $this->lines()->forCheck($check));
    }

    public function test_an_explicit_empty_list_warns_about_recent_checks_and_an_unset_list_does_not(): void
    {
        $check = $this->check([], $this->settings(recentChecks: ['lint'], rulesUnavailable: self::REFUSED));

        $empty = $this->lines(requiredChecks: new ResolvedSetting(RepositorySettings::REQUIRED_CHECKS_SETTING, [], SettingSource::File))->forCheck($check);

        self::assertCount(2, $empty);
        self::assertSame(self::P.'required_checks.unlisted.title', $empty[1]->titleKey);
        self::assertSame('lint', $empty[1]->detailParameters['%checks%']);
        self::assertCount(1, $this->lines()->forCheck($check));
    }

    public function test_the_unlisted_row_stays_quiet_when_classic_protection_can_hold_more_checks(): void
    {
        $settings = new RepositorySettings('main', ['squash'], 15368, new RepositoryRules(true, false, null, ['lint'], [], true, true), [], ['lint', 'extra'], true);

        self::assertSame([], $this->lines()->forCheck($this->check([], $settings)));
    }

    public function test_the_never_ran_row_stays_quiet_when_the_recent_list_is_cut_off(): void
    {
        $recent = array_map(static fn (int $i): string => 'c'.$i, range(1, RepositorySettings::MAX_RECENT_CHECKS));
        $check = $this->check([], $this->settings(recentChecks: $recent, requiredChecks: [...$recent, 'beyond']));

        self::assertSame([], $this->lines()->forCheck($check));
    }

    public function test_an_invalid_value_on_a_capable_repository_adds_no_ignored_warning(): void
    {
        $check = $this->check([], $this->settings());

        self::assertSame([], $this->lines(requiredChecks: new ResolvedSetting(RepositorySettings::REQUIRED_CHECKS_SETTING, null, SettingSource::Invalid))->forCheck($check));
    }

    /** @param list<string> $conflicts */
    private function lines(array $conflicts = [], ?ResolvedSetting $requiredChecks = null): GitHubRepositoryCheckLines
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $settings = $this->createStub(WorkflowSettings::class);
        $settings->method('projectConflicts')->willReturn($conflicts);
        $settings->method('forRepository')->willReturn($requiredChecks ?? new ResolvedSetting(RepositorySettings::REQUIRED_CHECKS_SETTING, null, SettingSource::Unset));

        return new GitHubRepositoryCheckLines($translator, $settings);
    }

    /**
     * @param list<array{key: string, mark: string, state: string, gaps: list<string>, parameters: array<string, mixed>}> $items
     */
    private function check(array $items, ?RepositorySettings $settings): ForgeRepositoryCheck
    {
        $project = new \App\Module\Project\Entity\Project(new User(fullName: 'Riley', email: 'lines@example.com', password: 'x'), 'lines');
        new \ReflectionProperty($project, 'id')->setValue($project, Uuid::v7());
        $repository = new ForgeRepository($project, 'github', '1', 'acme/app', ForgeRepositorySource::Hook);
        new \ReflectionProperty($repository, 'id')->setValue($repository, Uuid::v7());
        $check = new ForgeRepositoryCheck($repository, $project);
        $check->items = $items;
        $check->settings = null === $settings ? null : json_decode(json_encode($settings, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);

        return $check;
    }

    /**
     * @param list<string> $gaps
     *
     * @return array{key: string, mark: string, state: string, gaps: list<string>, parameters: array<string, mixed>}
     */
    private function item(string $key, string $mark, array $gaps): array
    {
        return ['key' => $key, 'mark' => $mark, 'state' => [] === $gaps ? 'fits' : 'gap', 'gaps' => $gaps, 'parameters' => []];
    }

    /**
     * @param list<string>            $recentChecks
     * @param list<string>            $requiredChecks
     * @param list<string>            $mergeMethods
     * @param list<RepositoryRuleset> $rulesets
     * @param list<string>|null       $allowedMergeMethods
     */
    private function settings(array $recentChecks = [], array $requiredChecks = [], array $mergeMethods = ['squash'], array $rulesets = [], ?array $allowedMergeMethods = null, ?string $rulesUnavailable = null): RepositorySettings
    {
        return new RepositorySettings('main', $mergeMethods, 15368, new RepositoryRules(true, false, $allowedMergeMethods, $requiredChecks, [], true, true), $rulesets, $recentChecks, false, $rulesUnavailable);
    }
}
