<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Mcp;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Contract\ApprovalRuleWriter;
use App\Module\Workflow\Mcp\WorkflowGetTool;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\McpTokenScenario;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WorkflowGetToolTest extends KernelTestCase
{
    use McpTokenScenario;
    use WorkflowProjects;

    private const array CODE_CHECKS = [
        'The worktree of the card is still available, or the agent can make it again',
        'The gate commands of .loupe/lifecycle.md',
        'Read access to the CI check logs',
    ];

    private const string DISCOVERY_CHECK = 'The Loupe plugin skills are installed for the agent, with the loupe-discovery skill';
    private const string ANALYSIS_CHECK = 'The Loupe plugin skills are installed for the agent, with the loupe-analysis skill';
    private const string ANALYSIS_BRIDGE_CHECK = 'The bridge rule file maps the analysis kind, or sets appPrompts to true';
    private const string DISCOVERY_REVISE_BRIDGE_CHECK = 'The bridge rule file maps the discovery-revise kind, or sets appPrompts to true';

    private const string DISCOVERY_BRIDGE_CHECK = 'The bridge rule file maps the discovery kind, or sets appPrompts to true';

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_a_lifecycle_project_lists_its_kinds_with_their_checks(): void
    {
        $project = $this->workflowProject('workflow-get-lifecycle');
        $this->bindLifecycle($project);
        $this->actAsMcpTokenBoundTo($project);

        $answer = $this->tool()();

        self::assertSame(['key' => 'lifecycle', 'version' => 29], $answer['template']);
        self::assertTrue($answer['approvalCoversConflictResolution']);
        self::assertSame('epic/{number}', $answer['epicBranch']);
        $kinds = array_column($answer['kinds'], null, 'kind');
        self::assertSame([
            'product-design', 'product-design-revise', 'design-review', 'tech-design', 'tech-design-revise', 'tech-design-recheck', 'implement', 'breakdown',
            'fix', 'review', 'rebase-stacked', 'sync', 'merge', 'teardown', 'epic-preview', 'sync-epic', 'discovery', 'discovery-revise', 'analysis',
        ], array_column($answer['kinds'], 'kind'));
        self::assertSame('template', $kinds['implement']['origin']);
        self::assertSame(['implement'], $kinds['implement']['rules']);
        self::assertNotContains('The agent pushes as its own GitHub account', $kinds['implement']['checks']);
        self::assertCount(5, $kinds['implement']['checks']);
        self::assertSame(['kind' => 'fix', 'origin' => 'template', 'rules' => ['fix-in-implementation', 'fix-agent-review', 'fix-in-review', 'fix-agent-review-in-review'], 'checks' => self::CODE_CHECKS], $kinds['fix']);
        self::assertSame(['kind' => 'review', 'origin' => 'template', 'rules' => ['agent-review', 'agent-review-in-review'], 'checks' => ['A bridge work entry for the review kind', 'The Loupe plugin skills are installed for the agent']], $kinds['review']);
        self::assertSame(['kind' => 'sync', 'origin' => 'template', 'rules' => ['update-behind'], 'checks' => []], $kinds['sync']);
        self::assertSame(['kind' => 'merge', 'origin' => 'template', 'rules' => ['merge-ready', 'merge-ready-epic-child'], 'checks' => []], $kinds['merge']);
        self::assertSame('app', $kinds['discovery']['origin']);
        self::assertContains(self::DISCOVERY_CHECK, $kinds['discovery']['checks']);
    }

    public function test_a_lifecycle_project_lists_its_columns_in_board_order_with_their_slots(): void
    {
        $project = $this->workflowProject('workflow-get-columns');
        $this->bindLifecycle($project);
        $this->actAsMcpTokenBoundTo($project);

        $columns = $this->tool()()['columns'];

        self::assertSame(
            ['backlog' => null, 'next' => 'next', 'in-progress' => 'implementation', 'done' => null, 'product-design' => 'product-design', 'tech-design' => 'tech-design', 'in-review' => 'in-review'],
            array_column($columns, 'slot', 'slug'),
        );
        self::assertSame(['slug' => 'backlog', 'label' => 'Backlog', 'slot' => null, 'backlog' => true, 'terminal' => false], $columns[0]);
        self::assertSame(['slug' => 'done', 'label' => 'Done', 'slot' => null, 'backlog' => false, 'terminal' => true], $columns[3]);
    }

    public function test_a_simple_project_lists_teardown_with_no_check(): void
    {
        $project = $this->workflowProject('workflow-get-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($project, 'simple', []));
        $this->actAsMcpTokenBoundTo($project);

        $answer = $this->tool()();

        self::assertSame(['key' => 'simple', 'version' => 10], $answer['template']);
        self::assertTrue($answer['approvalCoversConflictResolution']);
        self::assertSame([
            ['kind' => 'teardown', 'origin' => 'template', 'rules' => ['teardown'], 'checks' => []],
            ['kind' => 'discovery', 'origin' => 'app', 'rules' => ['discovery'], 'checks' => [self::DISCOVERY_CHECK, self::DISCOVERY_BRIDGE_CHECK]],
            ['kind' => 'discovery-revise', 'origin' => 'app', 'rules' => ['discovery-revise'], 'checks' => [self::DISCOVERY_CHECK, self::DISCOVERY_REVISE_BRIDGE_CHECK]],
            ['kind' => 'analysis', 'origin' => 'app', 'rules' => ['insights.analysis'], 'checks' => [self::ANALYSIS_CHECK, self::ANALYSIS_BRIDGE_CHECK]],
        ], $answer['kinds']);
        self::assertSame([null], array_values(array_unique(array_column($answer['columns'], 'slot'))));
    }

    public function test_it_returns_the_approval_rule_a_project_changed(): void
    {
        $project = $this->workflowProject('workflow-get-approval');
        $this->bindLifecycle($project);
        $writer = self::getContainer()->get(ApprovalRuleWriter::class);
        self::assertInstanceOf(ApprovalRuleWriter::class, $writer);
        self::assertTrue($writer->setCoversConflictResolution($project->id ?? throw new \LogicException('Not flushed.'), false));
        $this->actAsMcpTokenBoundTo($project);

        self::assertFalse($this->tool()()['approvalCoversConflictResolution']);
    }

    public function test_a_project_with_no_workflow_is_refused(): void
    {
        $this->actAsMcpTokenBoundTo($this->workflowProject('workflow-get-unbound'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('This project has no workflow yet.');

        $this->tool()();
    }

    private function tool(): WorkflowGetTool
    {
        $tool = self::getContainer()->get(WorkflowGetTool::class);
        self::assertInstanceOf(WorkflowGetTool::class, $tool);

        return $tool;
    }
}
