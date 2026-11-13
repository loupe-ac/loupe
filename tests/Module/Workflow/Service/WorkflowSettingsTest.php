<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Workflow\Contract\SettingSource;
use PHPUnit\Framework\Attributes\DataProvider;

final class WorkflowSettingsTest extends WorkflowSettingsTestCase
{
    public function test_a_valid_file_value_wins_over_the_stored_value_and_the_default(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('resolve-file');
        $this->bindLifecycle($project);
        $repository = $this->repository($project, 'acme/app');
        $this->store($project, $repository, 'epic-child-merge-method', 'rebase');
        $this->read($project, $repository, ['epic-child-merge-method' => 'merge']);

        $setting = $this->settings()->forRepository($project->requireId(), $repository, 'epic-child-merge-method');

        self::assertSame('merge', $setting->value);
        self::assertSame(SettingSource::File, $setting->source);
    }

    public function test_an_invalid_file_value_reads_as_unset_and_ignores_the_stored_value_and_the_default(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('resolve-invalid');
        $this->bindLifecycle($project);
        $repository = $this->repository($project, 'acme/app');
        $this->store($project, $repository, 'epic-child-merge-method', 'rebase');
        $this->read($project, $repository, ['epic-child-merge-method' => 'fast-forward']);
        $this->store($project, $repository, 'epic-child-merge-method', 'rebase');

        $setting = $this->settings()->forRepository($project->requireId(), $repository, 'epic-child-merge-method');

        self::assertNull($setting->value);
        self::assertSame(SettingSource::Invalid, $setting->source);
        self::assertCount(1, $setting->warnings);
    }

    public function test_accepts_tells_whether_the_declared_setting_takes_the_raw_value(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('accepts-value');
        $this->bindLifecycle($project);
        $id = $project->requireId();

        self::assertTrue($this->settings()->accepts($id, 'merge-approval', 'person'));
        self::assertTrue($this->settings()->accepts($id, 'merge-approval', 'agent'));
        self::assertFalse($this->settings()->accepts($id, 'merge-approval', 'maybe'));
        self::assertFalse($this->settings()->accepts($id, 'merge-approval', true));
        self::assertFalse($this->settings()->accepts($id, 'no-such-key', 'person'));
    }

    public function test_a_list_value_resolves_from_the_file(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('list-value');
        $this->bindLifecycle($project);
        $repository = $this->repository($project, 'acme/app');

        $this->read($project, $repository, ['required-checks' => ['lint', 'tests']]);

        $resolved = $this->settings()->forRepository($project->requireId(), $repository, 'required-checks');
        self::assertSame(['lint', 'tests'], $resolved->value);
        self::assertSame(SettingSource::File, $resolved->source);
    }

    public function test_an_empty_list_is_a_stated_value_and_unset_is_not(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('list-empty');
        $this->bindLifecycle($project);
        $repository = $this->repository($project, 'acme/app');

        self::assertNull($this->settings()->forRepository($project->requireId(), $repository, 'required-checks')->value);

        $this->read($project, $repository, ['required-checks' => []]);

        $resolved = $this->settings()->forRepository($project->requireId(), $repository, 'required-checks');
        self::assertSame([], $resolved->value);
        self::assertSame(SettingSource::File, $resolved->source);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidLists(): iterable
    {
        yield 'a bare string' => ['lint'];
        yield 'a list with an empty name' => [['lint', '']];
        yield 'a list with a number' => [['lint', 7]];
        yield 'a map' => [['name' => 'lint']];
    }

    #[DataProvider('invalidLists')]
    public function test_an_invalid_list_reads_as_unset_and_warns(mixed $value): void
    {
        self::bootKernel();
        $project = $this->workflowProject('list-invalid');
        $this->bindLifecycle($project);
        $repository = $this->repository($project, 'acme/app');

        $this->read($project, $repository, ['required-checks' => $value]);

        $resolved = $this->settings()->forRepository($project->requireId(), $repository, 'required-checks');
        self::assertNull($resolved->value);
        self::assertSame(SettingSource::Invalid, $resolved->source);
        self::assertStringContainsString('a list of names', $resolved->warnings[0]);
    }

    public function test_a_stored_value_applies_while_the_file_states_no_key(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('resolve-stored');
        $this->bindLifecycle($project);
        $repository = $this->repository($project, 'acme/app');
        $other = $this->repository($project, 'acme/other');
        $this->store($project, $repository, 'merge-approval', 'agent');

        $setting = $this->settings()->forRepository($project->requireId(), $repository, 'merge-approval');
        $sibling = $this->settings()->forRepository($project->requireId(), $other, 'merge-approval');

        self::assertSame('agent', $setting->value);
        self::assertSame(SettingSource::Stored, $setting->source);
        self::assertNull($sibling->value);
        self::assertSame(SettingSource::Unset, $sibling->source);
    }

    public function test_the_template_default_applies_when_nothing_else_does(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('resolve-default');
        $this->bindLifecycle($project);
        $repository = $this->repository($project, 'acme/app');

        $setting = $this->settings()->forRepository($project->requireId(), $repository, 'epic-child-merge-method');

        self::assertSame('squash', $setting->value);
        self::assertSame(SettingSource::Default, $setting->source);
    }

    public function test_removing_the_key_after_the_first_read_does_not_bring_the_stored_value_back(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('resolve-removed');
        $this->bindLifecycle($project);
        $repository = $this->repository($project, 'acme/app');
        $this->store($project, $repository, 'merge-approval', 'agent');
        $this->read($project, $repository, ['merge-approval' => 'person']);
        $this->read($project, $repository, [], blob: 'blob-2');

        $setting = $this->settings()->forRepository($project->requireId(), $repository, 'merge-approval');

        self::assertNull($setting->value);
        self::assertSame(SettingSource::Unset, $setting->source);
    }

    public function test_a_key_the_template_does_not_declare_reads_as_unset(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('resolve-undeclared');
        $this->bindLifecycle($project);
        $repository = $this->repository($project, 'acme/app');

        $setting = $this->settings()->forRepository($project->requireId(), $repository, 'no-such-key');

        self::assertSame(SettingSource::Unset, $setting->source);
    }

    #[DataProvider('wrongScopeCalls')]
    public function test_a_key_asked_in_the_wrong_scope_throws(bool $forRepository, string $key): void
    {
        self::bootKernel();
        $project = $this->workflowProject('resolve-scope');
        $this->bindLifecycle($project);
        $repository = $this->repository($project, 'acme/app');

        $this->expectException(\LogicException::class);
        if ($forRepository) {
            $this->settings()->forRepository($project->requireId(), $repository, $key);
        } else {
            $this->settings()->forProject($project->requireId(), $key);
        }
    }

    /** @return iterable<string, array{bool, string}> */
    public static function wrongScopeCalls(): iterable
    {
        yield 'a project key for a repository' => [true, 'epic-branches'];
        yield 'a repository key for the project' => [false, 'merge-approval'];
    }

    public function test_one_repository_that_sets_a_project_key_is_enough(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('project-one');
        $this->bindLifecycle($project);
        $this->repository($project, 'acme/silent');
        $setting = $this->repository($project, 'acme/app');
        $this->read($project, $setting, ['epic-branch-pattern' => 'feature/{number}']);

        $resolved = $this->settings()->forProject($project->requireId(), 'epic-branch-pattern');

        self::assertSame('feature/{number}', $resolved->value);
        self::assertSame(SettingSource::File, $resolved->source);
    }

    public function test_repositories_that_agree_give_the_file_value(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('project-agree');
        $this->bindLifecycle($project);
        $this->read($project, $this->repository($project, 'acme/a'), ['epic-branches' => false]);
        $this->read($project, $this->repository($project, 'acme/b'), ['epic-branches' => false]);

        $resolved = $this->settings()->forProject($project->requireId(), 'epic-branches');

        self::assertFalse($resolved->value);
        self::assertSame(SettingSource::File, $resolved->source);
        self::assertSame([], $resolved->warnings);
    }

    public function test_repositories_that_differ_give_a_conflict_that_resolves_from_the_template_copy(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('project-conflict');
        $this->bindingValues($project, ['epic-branch-pattern' => 'project/{number}']);
        $this->read($project, $this->repository($project, 'acme/a'), ['epic-branch-pattern' => 'a/{number}']);
        $this->read($project, $this->repository($project, 'acme/b'), ['epic-branch-pattern' => 'b/{number}']);

        $resolved = $this->settings()->forProject($project->requireId(), 'epic-branch-pattern');

        self::assertSame('project/{number}', $resolved->value);
        self::assertSame(SettingSource::Conflict, $resolved->source);
        self::assertCount(1, $resolved->warnings);
        self::assertStringContainsString('epic-branch-pattern', $resolved->warnings[0]);
        self::assertStringContainsString('acme/a', $resolved->warnings[0]);
        self::assertStringContainsString('acme/b', $resolved->warnings[0]);
    }

    public function test_project_conflicts_lists_the_conflict_warning_of_each_conflicting_key(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('project-conflicts-list');
        $this->bindingValues($project, ['epic-branch-pattern' => 'project/{number}']);
        $a = $this->repository($project, 'acme/a');
        $this->read($project, $a, ['epic-branch-pattern' => 'a/{number}']);
        $this->read($project, $this->repository($project, 'acme/b'), ['epic-branch-pattern' => 'b/{number}']);
        $c = $this->repository($project, 'acme/c');
        $this->read($project, $c, []);

        self::assertSame([], $this->settings()->projectConflicts($project->requireId(), $c));
        $conflicts = $this->settings()->projectConflicts($project->requireId(), $a);

        self::assertCount(1, $conflicts);
        self::assertStringContainsString('acme/a', $conflicts[0]);
        self::assertStringContainsString('acme/b', $conflicts[0]);
    }

    public function test_a_conflict_with_no_template_value_resolves_from_the_default(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('project-conflict-default');
        $this->bindLifecycle($project);
        $this->read($project, $this->repository($project, 'acme/a'), ['epic-branches' => true]);
        $this->read($project, $this->repository($project, 'acme/b'), ['epic-branches' => false]);

        $resolved = $this->settings()->forProject($project->requireId(), 'epic-branches');

        self::assertTrue($resolved->value);
        self::assertSame(SettingSource::Conflict, $resolved->source);
    }

    public function test_an_invalid_project_value_counts_as_no_value_and_warns(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('project-invalid');
        $this->bindLifecycle($project);
        $this->read($project, $this->repository($project, 'acme/bad'), ['epic-branch-pattern' => 'no-placeholder']);
        $this->read($project, $this->repository($project, 'acme/good'), ['epic-branch-pattern' => 'good/{number}']);

        $resolved = $this->settings()->forProject($project->requireId(), 'epic-branch-pattern');

        self::assertSame('good/{number}', $resolved->value);
        self::assertSame(SettingSource::File, $resolved->source);
        self::assertCount(1, $resolved->warnings);
        self::assertStringContainsString('acme/bad', $resolved->warnings[0]);
    }

    public function test_an_invalid_value_alone_falls_back_to_the_template_copy(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('project-invalid-alone');
        $this->bindingValues($project, ['epic-branches' => false]);
        $this->read($project, $this->repository($project, 'acme/bad'), ['epic-branches' => 'maybe']);

        $resolved = $this->settings()->forProject($project->requireId(), 'epic-branches');

        self::assertFalse($resolved->value);
        self::assertSame(SettingSource::Template, $resolved->source);
        self::assertCount(1, $resolved->warnings);
    }

    public function test_no_file_value_gives_the_template_copy_and_then_the_default(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('project-none');
        $this->bindingValues($project, ['epic-branch-pattern' => 'project/{number}']);
        $this->repository($project, 'acme/app');

        $pattern = $this->settings()->forProject($project->requireId(), 'epic-branch-pattern');
        $enabled = $this->settings()->forProject($project->requireId(), 'epic-branches');

        self::assertSame('project/{number}', $pattern->value);
        self::assertSame(SettingSource::Template, $pattern->source);
        self::assertTrue($enabled->value);
        self::assertSame(SettingSource::Default, $enabled->source);
    }

    public function test_a_project_with_no_repository_resolves_from_the_template(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('project-no-repository');

        $resolved = $this->settings()->forProject($project->requireId(), 'epic-branch-pattern');

        self::assertSame('epic/{number}', $resolved->value);
        self::assertSame(SettingSource::Default, $resolved->source);
    }
}
