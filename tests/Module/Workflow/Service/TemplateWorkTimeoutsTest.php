<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Workflow\Contract\WorkTimeouts;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TemplateWorkTimeoutsTest extends KernelTestCase
{
    use WorkflowProjects;

    public function test_a_bound_project_gets_the_timeout_of_its_stored_copy(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('work-timeouts-bound');
        $binding = $this->bindLifecycle($project);
        $binding->definition = [...$binding->definition, 'workTimeoutMinutes' => 45];
        $this->em()->flush();

        self::assertSame(45, $this->timeouts()->minutes($project->requireId()));
    }

    public function test_an_unbound_project_gets_the_timeout_of_the_simple_template(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('work-timeouts-unbound');

        self::assertSame(120, $this->timeouts()->minutes($project->requireId()));
    }

    private function timeouts(): WorkTimeouts
    {
        $timeouts = self::getContainer()->get(WorkTimeouts::class);
        self::assertInstanceOf(WorkTimeouts::class, $timeouts);

        return $timeouts;
    }
}
