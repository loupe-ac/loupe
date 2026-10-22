<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Contract\DesignReviewTags;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class TemplateDesignReviewTagsTest extends KernelTestCase
{
    use WorkflowProjects;

    public function test_a_bound_project_gets_the_tags_of_the_design_review_rules_of_its_copy(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('design-review-tags-bound');
        $binding = $this->bindLifecycle($project);
        $definition = $binding->definition;
        foreach ($definition['rules'] as $index => $rule) {
            if ('design-review' === ($rule['then']['request']['role'] ?? null) && 'product-design' === $rule['then']['request']['document']['tag']) {
                $definition['rules'][$index]['then']['request']['document']['tag'] = 'custom-design';
            }
        }
        $binding->definition = $definition;
        $this->em()->flush();

        self::assertSame(['custom-design', 'tech-design'], $this->tags()->of($project->requireId()));
    }

    public function test_a_copy_with_no_design_review_rule_has_no_tags(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('design-review-tags-none');
        $binding = $this->bindLifecycle($project);
        $definition = $binding->definition;
        $definition['rules'] = array_values(array_filter(
            $definition['rules'],
            static fn (array $rule): bool => 'design-review' !== ($rule['then']['request']['role'] ?? null),
        ));
        $binding->definition = $definition;
        $this->em()->flush();

        self::assertSame([], $this->tags()->of($project->requireId()));
    }

    public function test_the_simple_template_asks_for_no_design_review(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('design-review-tags-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($project, 'simple', []));

        self::assertSame([], $this->tags()->of($project->requireId()));
    }

    public function test_an_unbound_project_gets_the_tags_of_the_simple_template(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('design-review-tags-unbound');

        self::assertSame([], $this->tags()->of($project->requireId()));
    }

    public function test_a_column_gets_the_tags_of_the_design_review_rules_of_its_slot(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('design-review-in-column');
        $this->bindLifecycle($project);
        $columns = $this->lifecycleColumns($project);
        $projectId = $project->requireId();

        self::assertSame(['product-design'], $this->tags()->reviewedInColumn($projectId, (string) $columns['product-design']));
        self::assertSame(['tech-design'], $this->tags()->reviewedInColumn($projectId, (string) $columns['tech-design']));
    }

    public function test_a_column_whose_slot_has_no_design_review_rule_gets_no_tags(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('design-review-in-other-column');
        $this->bindLifecycle($project);
        $columns = $this->lifecycleColumns($project);

        self::assertSame([], $this->tags()->reviewedInColumn($project->requireId(), (string) $columns['next']));
    }

    public function test_a_column_linked_to_no_slot_gets_no_tags(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('design-review-in-unlinked-column');
        $this->bindLifecycle($project);

        self::assertSame([], $this->tags()->reviewedInColumn($project->requireId(), (string) Uuid::v7()));
    }

    public function test_an_unbound_project_has_no_tags_in_any_column(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('design-review-in-column-unbound');
        $columns = $this->lifecycleColumns($project);

        self::assertSame([], $this->tags()->reviewedInColumn($project->requireId(), (string) $columns['tech-design']));
    }

    private function tags(): DesignReviewTags
    {
        $tags = self::getContainer()->get(DesignReviewTags::class);
        self::assertInstanceOf(DesignReviewTags::class, $tags);

        return $tags;
    }
}
