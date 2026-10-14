<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Service\TemplateStageWorks;
use App\Tests\Module\Workflow\WorkflowProjects;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class TemplateStageWorksTest extends KernelTestCase
{
    use WorkflowProjects;

    /** @return iterable<string, array{string, ?string}> */
    public static function slots(): iterable
    {
        yield 'a slot that names work' => ['tech-design', 'tech-design'];
        yield 'another slot that names work' => ['in-progress', 'implement'];
        yield 'a slot that names none' => ['product-design', null];
        yield 'the backlog' => ['backlog', null];
        yield 'a terminal column' => ['done', null];
    }

    #[DataProvider('slots')]
    public function test_the_stage_work_follows_the_slot_of_the_column_of_the_card(string $slug, ?string $expected): void
    {
        self::bootKernel();
        $project = $this->workflowProject('stage-works-'.$slug);
        $this->bindLifecycle($project);
        $card = $this->card($project, $slug);

        self::assertSame($expected, $this->stageWorks()->of($project->requireId(), $card->id ?? throw new \LogicException('Stored.')));
    }

    public function test_a_template_with_no_stage_work_names_none(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('stage-works-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($project, 'simple', []));
        $card = $this->card($project, 'in-progress');

        self::assertNull($this->stageWorks()->of($project->requireId(), $card->id ?? throw new \LogicException('Stored.')));
    }

    public function test_a_card_of_another_project_gets_none(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('stage-works-mine');
        $this->bindLifecycle($project);
        $other = $this->workflowProject('stage-works-other');
        $this->bindLifecycle($other);
        $card = $this->card($other, 'tech-design');

        self::assertNull($this->stageWorks()->of($project->requireId(), $card->id ?? throw new \LogicException('Stored.')));
    }

    public function test_an_unknown_card_gets_none(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('stage-works-unknown');
        $this->bindLifecycle($project);

        self::assertNull($this->stageWorks()->of($project->requireId(), Uuid::v7()));
    }

    private function card(Project $project, string $columnSlug): Card
    {
        $handler = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $handler);

        return $handler(new CreateCardCommand(
            project: $project,
            title: 'Card',
            body: 'Body',
            type: 'feature',
            column: $this->column($project, $columnSlug),
            reporter: Actor::Human,
        ));
    }

    private function stageWorks(): TemplateStageWorks
    {
        $stageWorks = self::getContainer()->get(TemplateStageWorks::class);
        self::assertInstanceOf(TemplateStageWorks::class, $stageWorks);

        return $stageWorks;
    }
}
