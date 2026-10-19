<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Workflow\Entity\WorkflowBinding;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Module\Workflow\Service\TemplateApprovalRules;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class TemplateApprovalRulesTest extends KernelTestCase
{
    use WorkflowProjects;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_a_project_bound_to_no_template_lets_an_approval_cover_a_conflict_resolution(): void
    {
        $project = $this->workflowProject('approval-rules-unbound');

        self::assertTrue($this->rules()->coversConflictResolution($this->id($project->id)));
    }

    public function test_writing_to_a_project_bound_to_no_template_stores_nothing(): void
    {
        $project = $this->workflowProject('approval-rules-unbound-write');

        self::assertFalse($this->rules()->setCoversConflictResolution($this->id($project->id), false));
    }

    public function test_a_written_value_is_stored_in_the_copy_and_read_back(): void
    {
        $project = $this->workflowProject('approval-rules-write');
        $this->bindLifecycle($project);
        $id = $this->id($project->id);
        self::assertTrue($this->rules()->coversConflictResolution($id));

        self::assertTrue($this->rules()->setCoversConflictResolution($id, false));

        self::assertFalse($this->rules()->coversConflictResolution($id));
        $this->em()->clear();
        $binding = self::getContainer()->get(WorkflowBindingRepository::class)->findOneByProjectId($id);
        self::assertInstanceOf(WorkflowBinding::class, $binding);
        self::assertFalse($binding->definition[TemplateApprovalRules::KEY]);
        self::assertSame(8, $binding->templateVersion);
    }

    private function rules(): TemplateApprovalRules
    {
        $rules = self::getContainer()->get(TemplateApprovalRules::class);
        self::assertInstanceOf(TemplateApprovalRules::class, $rules);

        return $rules;
    }

    private function id(?Uuid $id): Uuid
    {
        return $id ?? throw new \LogicException('The project is not flushed.');
    }
}
