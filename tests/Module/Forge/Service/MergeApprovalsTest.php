<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\Service;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositoryCheck;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Service\MergeApprovals;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Command\BindWorkflowTemplateHandler;
use App\Tests\Module\Workflow\Service\WorkflowSettingsTestCase;
use Symfony\Component\Uid\Uuid;

final class MergeApprovalsTest extends WorkflowSettingsTestCase
{
    private Project $project;
    private ForgeRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->project = $this->workflowProject('merge-approvals');
        $this->bindLifecycle($this->project);
        $this->resetServices();
        $this->repository = $this->installed($this->project, 'acme/app');
    }

    public function test_a_repository_with_no_answer_is_unanswered_when_loupe_can_post_its_check(): void
    {
        $approval = $this->approvals()->of($this->repository);

        self::assertTrue($approval->declared);
        self::assertTrue($approval->unset);
        self::assertFalse($approval->person);
        self::assertTrue($approval->unanswered(true));
        self::assertTrue($approval->needsPerson(true));
        self::assertFalse($approval->unanswered(false), 'Without a check, the case is not the question.');
        self::assertFalse($approval->needsPerson(false));
    }

    public function test_the_rules_that_require_a_review_leave_nothing_to_ask(): void
    {
        $this->rules(requiresReview: true);

        $approval = $this->approvals()->of($this->repository);

        self::assertTrue($approval->requiresReview);
        self::assertFalse($approval->unanswered(true));
        self::assertFalse($approval->needsPerson(true));
    }

    public function test_rules_that_do_not_require_a_review_leave_the_case_open(): void
    {
        $this->rules(requiresReview: false);

        self::assertTrue($this->approvals()->of($this->repository)->unanswered(true));
    }

    public function test_an_answer_of_agent_ends_the_case_and_asks_for_no_person(): void
    {
        $this->read($this->project, $this->idOf($this->repository), ['merge-approval' => 'agent']);

        $approval = $this->approvals()->of($this->repository);

        self::assertFalse($approval->unset);
        self::assertFalse($approval->unanswered(true));
        self::assertFalse($approval->needsPerson(true));
    }

    public function test_an_answer_of_person_needs_a_person_without_a_check(): void
    {
        $this->read($this->project, $this->idOf($this->repository), ['merge-approval' => 'person']);

        $approval = $this->approvals()->of($this->repository);

        self::assertTrue($approval->person);
        self::assertFalse($approval->unanswered(true));
        self::assertTrue($approval->needsPerson(true));
        self::assertTrue($approval->needsPerson(false));
    }

    public function test_a_stored_agent_answer_keeps_a_migrated_repository_merging(): void
    {
        $this->store($this->project, $this->idOf($this->repository), 'merge-approval', 'agent');

        $approval = $this->approvals()->of($this->repository);

        self::assertFalse($approval->unanswered(true));
        self::assertFalse($approval->needsPerson(true));
    }

    public function test_a_value_that_is_not_allowed_reads_as_unanswered(): void
    {
        $this->store($this->project, $this->idOf($this->repository), 'merge-approval', 'agent');
        $this->read($this->project, $this->idOf($this->repository), ['merge-approval' => 'sometimes']);

        $approval = $this->approvals()->of($this->repository);

        self::assertTrue($approval->invalidInFile);
        self::assertTrue($approval->unanswered(true), 'A typo never keeps an older and wider answer.');
    }

    public function test_a_template_without_the_setting_never_asks(): void
    {
        $simple = $this->workflowProject('merge-approvals-simple');
        $handler = self::getContainer()->get(BindWorkflowTemplateHandler::class);
        self::assertInstanceOf(BindWorkflowTemplateHandler::class, $handler);
        $handler(new BindWorkflowTemplateCommand($simple, 'simple', []));
        $this->resetServices();
        $repository = $this->installed($simple, 'acme/simple');

        $approval = $this->approvals()->of($repository);

        self::assertFalse($approval->declared);
        self::assertFalse($approval->unanswered(true));
        self::assertFalse($approval->needsPerson(true));
    }

    private function approvals(): MergeApprovals
    {
        $approvals = self::getContainer()->get(MergeApprovals::class);
        self::assertInstanceOf(MergeApprovals::class, $approvals);

        return $approvals;
    }

    private function installed(Project $project, string $path): ForgeRepository
    {
        $repository = new ForgeRepository($this->em()->getReference(Project::class, $project->requireId()) ?? throw new \LogicException('No project.'), 'github', 'ext-'.uniqid(), $path, ForgeRepositorySource::Installation, '1');
        $this->em()->persist($repository);
        $this->em()->flush();

        return $repository;
    }

    private function idOf(ForgeRepository $repository): Uuid
    {
        return $repository->id ?? throw new \LogicException('The repository is not flushed.');
    }

    private function rules(bool $requiresReview): void
    {
        $check = new ForgeRepositoryCheck($this->repository, $this->repository->project);
        $check->settings = [
            'defaultBranch' => 'main',
            'mergeMethods' => ['squash'],
            'loupeAppId' => 1,
            'rules' => ['pullRequestRule' => true, 'requiresReview' => $requiresReview, 'allowedMergeMethods' => null, 'requiredChecks' => [], 'approvalSources' => [], 'forbidsForcePush' => true, 'forbidsDeletion' => true],
            'rulesets' => [],
            'recentChecks' => [],
            'classicProtection' => false,
            'rulesUnavailable' => null,
        ];
        $this->em()->persist($check);
        $this->em()->flush();
    }
}
