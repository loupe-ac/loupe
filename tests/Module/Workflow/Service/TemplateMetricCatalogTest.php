<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Contract\BoardColumns;
use App\Module\Workflow\Contract\CountFilter;
use App\Module\Workflow\Contract\MetricType;
use App\Module\Workflow\Contract\SlotKeys;
use App\Module\Workflow\Contract\TemplateMetric;
use App\Module\Workflow\Contract\TemplateOutcome;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use App\Module\Workflow\Service\TemplateMetricCatalog;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Module\Workflow\Template\TemplateParser;
use App\Module\Workflow\Template\TemplateSource;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TemplateMetricCatalogTest extends KernelTestCase
{
    use WorkflowProjects;

    public function test_a_bound_project_gets_the_outcomes_and_metrics_of_its_stored_copy(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('metrics-bound');
        $binding = $this->bindLifecycle($project);
        $binding->definition = $binding->definition + ['metrics' => [
            ['key' => 'design-rounds', 'label' => 'Design rounds', 'description' => 'The design revise rounds.', 'count' => ['role' => 'design-revise']],
        ]];
        $this->em()->flush();

        $set = $this->catalog()->forProject($project->requireId());

        self::assertEquals([new TemplateMetric('design-rounds', 'Design rounds', 'The design revise rounds.', MetricType::Count, new CountFilter(role: 'design-revise'))], $set->metrics);
        self::assertSame($set->metrics[0], $set->metric('design-rounds'));
        self::assertNull($set->metric('nothing'));
        self::assertEquals([new TemplateOutcome('merged', true)], $set->outcomes);
        self::assertEquals(new TemplateOutcome('merged', true), $set->delivered());
    }

    public function test_the_set_maps_each_slot_to_its_column(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('metrics-columns');
        $binding = $this->bindLifecycle($project);
        $binding->definition = $binding->definition + ['metrics' => [
            ['key' => 'design-rounds', 'label' => 'Design rounds', 'description' => 'The design revise rounds.', 'count' => ['role' => 'design-revise']],
        ]];
        $this->em()->flush();

        $set = $this->catalog()->forProject($project->requireId());

        $columns = $this->lifecycleColumns($project);
        self::assertEquals($columns['in-review'], $set->slotColumns['in-review']);
        self::assertEquals($columns['implementation'], $set->slotColumns['implementation']);
        self::assertArrayHasKey(SlotKeys::BACKLOG, $set->slotColumns);
        self::assertArrayHasKey(SlotKeys::TERMINAL, $set->slotColumns);
    }

    public function test_a_project_with_no_metric_gets_no_slot_map(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('metrics-none');
        $this->bindLifecycle($project);

        $set = $this->catalog()->forProject($project->requireId());

        self::assertSame([], $set->metrics);
        self::assertSame([], $set->slotColumns);
    }

    public function test_an_unbound_project_gets_the_outcomes_of_the_simple_template(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('metrics-unbound');

        $set = $this->catalog()->forProject($project->requireId());

        self::assertEquals([new TemplateOutcome('merged', true)], $set->outcomes);
        self::assertSame([], $set->metrics);
    }

    public function test_a_simple_project_gets_the_outcomes_of_the_simple_template(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('metrics-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($project, 'simple', []));

        self::assertEquals([new TemplateOutcome('merged', true)], $this->catalog()->forProject($project->requireId())->outcomes);
    }

    private function catalog(): TemplateMetricCatalog
    {
        $container = self::getContainer();

        return new TemplateMetricCatalog(
            $container->get(TemplateSource::class),
            $container->get(ShippedTemplates::class),
            $container->get(TemplateParser::class),
            $container->get(ProjectRepository::class),
            $container->get(BoardColumns::class),
            $container->get(WorkflowSlotLinkRepository::class),
        );
    }
}
