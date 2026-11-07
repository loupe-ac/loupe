<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Workflow\Contract\EpicBranches;

final class TemplateEpicBranchesFileTest extends WorkflowSettingsTestCase
{
    public function test_the_pattern_of_the_file_names_the_epic_branch(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('epic-file');
        $this->bindLifecycle($project);
        $this->read($project, $this->repository($project, 'acme/app'), ['epic-branch-pattern' => 'work/e-{number}']);
        $this->resetServices();

        self::assertSame('work/e-7', $this->branches()->of($project->requireId(), 7));
    }

    public function test_a_file_that_turns_epic_branches_off_gives_none(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('epic-file-off');
        $this->bindLifecycle($project);
        $this->read($project, $this->repository($project, 'acme/app'), ['epic-branches' => false]);
        $this->resetServices();

        self::assertNull($this->branches()->of($project->requireId(), 7));
    }

    public function test_two_repositories_with_different_patterns_fall_back_to_the_project_value(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('epic-file-conflict');
        $this->bindingValues($project, ['epic-branch-pattern' => 'project/{number}']);
        $this->read($project, $this->repository($project, 'acme/a'), ['epic-branch-pattern' => 'a/{number}']);
        $this->read($project, $this->repository($project, 'acme/b'), ['epic-branch-pattern' => 'b/{number}']);
        $this->resetServices();

        self::assertSame('project/7', $this->branches()->of($project->requireId(), 7));
    }

    public function test_the_project_without_a_repository_keeps_the_template_branch(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('epic-file-none');
        $this->bindLifecycle($project);

        self::assertSame('epic/7', $this->branches()->of($project->requireId(), 7));
    }

    private function branches(): EpicBranches
    {
        $branches = self::getContainer()->get(EpicBranches::class);
        self::assertInstanceOf(EpicBranches::class, $branches);

        return $branches;
    }
}
