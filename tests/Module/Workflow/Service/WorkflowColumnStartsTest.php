<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Board\Service\BoardAutomation;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Action\Actions;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use App\Module\Workflow\Service\ProjectTemplates;
use App\Module\Workflow\Service\WorkflowAutomation;
use App\Module\Workflow\Service\WorkflowColumnStarts;
use App\Tests\Module\Workflow\WorkflowProjects;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class WorkflowColumnStartsTest extends KernelTestCase
{
    use WorkflowProjects;

    /** @return iterable<string, array{string, ?string}> */
    public static function columns(): iterable
    {
        yield 'next' => ['next', 'Waits until you start it.'];
        yield 'product design' => ['product-design', 'You write the product design with an agent, in a session that you join.'];
        yield 'tech design' => ['tech-design', 'An agent writes the tech design.'];
        yield 'a slot with no hint' => ['in-progress', null];
        yield 'the backlog' => ['backlog', null];
        yield 'a terminal column' => ['done', null];
    }

    #[DataProvider('columns')]
    public function test_the_hint_follows_the_slot_of_the_column(string $slug, ?string $expected): void
    {
        self::bootKernel();
        $project = $this->workflowProject('hint-'.$slug);
        $this->bindLifecycle($project);

        self::assertSame($expected, $this->hint($project, $slug));
    }

    public function test_a_board_with_automation_off_shows_no_hint(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('hint-automation-off');
        $this->bindLifecycle($project);
        $automation = self::getContainer()->get(BoardAutomation::class);
        self::assertInstanceOf(BoardAutomation::class, $automation);
        $automation->settingsForUpdate($project)->enabled = false;
        $this->em()->flush();

        self::assertNull($this->hint($project, 'tech-design'));
    }

    public function test_a_template_with_no_slots_shows_no_hint(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('hint-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($project, 'simple', []));

        self::assertNull($this->hint($project, 'in-progress'));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function workColumns(): iterable
    {
        yield 'next' => ['next', false];
        yield 'product design' => ['product-design', true];
        yield 'tech design' => ['tech-design', true];
        yield 'implementation' => ['in-progress', true];
        yield 'in review' => ['in-review', true];
        yield 'the backlog' => ['backlog', false];
        yield 'a terminal column' => ['done', false];
    }

    #[DataProvider('workColumns')]
    public function test_a_column_starts_work_when_a_rule_of_its_slot_asks_for_it(string $slug, bool $expected): void
    {
        self::bootKernel();
        $project = $this->workflowProject('work-'.$slug);
        $this->bindLifecycle($project);

        self::assertSame($expected, $this->starts()->startsWork($project->requireId(), $this->column($project, $slug)->ref()));
    }

    public function test_a_board_with_automation_off_starts_no_work(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('work-automation-off');
        $this->bindLifecycle($project);
        self::assertTrue($this->starts()->startsWork($project->requireId(), $this->column($project, 'tech-design')->ref()));
        $automation = self::getContainer()->get(BoardAutomation::class);
        self::assertInstanceOf(BoardAutomation::class, $automation);
        $automation->settingsForUpdate($project)->enabled = false;
        $this->em()->flush();

        self::assertFalse($this->starts()->startsWork($project->requireId(), $this->column($project, 'tech-design')->ref()));
    }

    public function test_a_template_with_no_slots_starts_no_work(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('work-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($project, 'simple', []));

        self::assertFalse($this->starts()->startsWork($project->requireId(), $this->column($project, 'in-progress')->ref()));
    }

    private function hint(Project $project, string $slug): ?string
    {
        return $this->starts()->hint($project->requireId(), $this->column($project, $slug)->ref());
    }

    private function starts(): WorkflowColumnStarts
    {
        return new WorkflowColumnStarts(
            $this->service(ProjectTemplates::class),
            $this->service(WorkflowAutomation::class),
            $this->service(Actions::class),
            $this->service(ProjectRepository::class),
            $this->service(WorkflowSlotLinkRepository::class),
            $this->service(TranslatorInterface::class),
            'en',
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }
}
