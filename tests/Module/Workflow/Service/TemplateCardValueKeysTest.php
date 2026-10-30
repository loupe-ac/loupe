<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Contract\CardValueType;
use App\Module\Workflow\Contract\TemplateValueKey;
use App\Module\Workflow\Service\TemplateCardValueKeys;
use App\Module\Workflow\Template\ProjectTemplateCopy;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Module\Workflow\Template\TemplateParser;
use App\Module\Workflow\Template\TemplateSource;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TemplateCardValueKeysTest extends KernelTestCase
{
    use WorkflowProjects;

    public function test_a_lifecycle_project_gets_the_keys_of_its_stored_copy(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('value-keys-bound');
        $this->bindLifecycle($project);

        $keys = $this->keys()->forProject($project->requireId());

        self::assertEquals([
            new TemplateValueKey(
                'tech-design-interactive',
                CardValueType::Bool,
                'workflow.value.tech_design_interactive.label',
                'workflow.value.tech_design_interactive.caption',
                'workflow.value.tech_design_interactive.help',
                'workflow.value.tech_design_interactive.history',
            ),
        ], $keys);
    }

    public function test_a_stored_copy_with_no_values_section_has_no_keys(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('value-keys-old-copy');
        $binding = $this->bindLifecycle($project);
        $definition = $binding->definition;
        unset($definition['values']);
        $binding->definition = $definition;
        $this->em()->flush();

        self::assertSame([], $this->keys()->forProject($project->requireId()));
    }

    public function test_a_simple_project_has_no_keys(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('value-keys-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($project, 'simple', []));

        self::assertSame([], $this->keys()->forProject($project->requireId()));
    }

    public function test_an_unbound_project_has_no_keys(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('value-keys-unbound');

        self::assertSame([], $this->keys()->forProject($project->requireId()));
    }

    public function test_a_reset_forgets_the_keys_it_read(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('value-keys-reset');
        $binding = $this->bindLifecycle($project);
        $keys = $this->keys();
        self::assertCount(1, $keys->forProject($project->requireId()));
        $definition = $binding->definition;
        unset($definition['values']);
        $binding->definition = $definition;
        $this->em()->flush();

        $source = self::getContainer()->get(TemplateSource::class);
        self::assertInstanceOf(ProjectTemplateCopy::class, $source);
        $source->reset();

        self::assertCount(1, $keys->forProject($project->requireId()));
        $keys->reset();
        self::assertSame([], $keys->forProject($project->requireId()));
    }

    private function keys(): TemplateCardValueKeys
    {
        $container = self::getContainer();

        return new TemplateCardValueKeys(
            $container->get(TemplateSource::class),
            $container->get(ShippedTemplates::class),
            $container->get(TemplateParser::class),
        );
    }
}
