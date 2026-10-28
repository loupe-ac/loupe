<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Workflow\Contract\WorkflowKeys;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TemplateWorkflowKeysTest extends KernelTestCase
{
    use WorkflowProjects;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_a_project_bound_to_no_template_has_no_key(): void
    {
        $project = $this->workflowProject('keys-unbound');

        self::assertNull($this->keys()->forProject($project->id ?? throw new \LogicException()));
    }

    public function test_a_bound_project_names_the_template_of_its_copy(): void
    {
        $project = $this->workflowProject('keys-bound');
        $this->bindLifecycle($project);

        self::assertSame('lifecycle', $this->keys()->forProject($project->id ?? throw new \LogicException()));
    }

    private function keys(): WorkflowKeys
    {
        $keys = self::getContainer()->get(WorkflowKeys::class);
        self::assertInstanceOf(WorkflowKeys::class, $keys);

        return $keys;
    }
}
