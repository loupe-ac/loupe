<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Mcp;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Contract\ApprovalRuleWriter;
use App\Module\Workflow\Entity\WorkflowRepositorySetting;
use App\Module\Workflow\Mcp\WorkflowGetTool;
use App\Module\Workflow\Repository\WorkflowRepositorySettingRepository;
use App\Module\Workflow\Repository\WorkflowSettingOverrideRepository;
use App\Module\Workflow\Service\ProjectTemplates;
use App\Module\Workflow\Service\SettingValues;
use App\Module\Workflow\Service\WorkflowSettingsWriter;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Module\Workflow\Template\TemplateVersion;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\McpTokenScenario;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WorkflowGetToolTest extends KernelTestCase
{
    use McpTokenScenario;
    use WorkflowProjects;

    private const array CODE_CHECKS = [
        'The worktree of the card is still available, or the agent can make it again',
        'The gate commands of .loupe/lifecycle.md',
        'Read access to the CI check logs',
        'Conflict check: CI and the gate. The Gate section of the profile runs each check that CI requires',
        'A gate step that uses a shared stack, such as e2e against one dev server, never runs in two workers at once. A lock in the gate command, or a bridge limit of one worker for the project, holds that. A rule in the profile alone does not count. When it fails, the actor is owner, and the action names the step and the lock or the limit to add',
    ];

    private const string DISCOVERY_CHECK = 'The loupe skill package is installed for the agent, with the loupe-discovery skill';
    private const string ANALYSIS_CHECK = 'The loupe skill package is installed for the agent, with the loupe-analysis skill';
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

        self::assertSame(['key' => 'lifecycle', 'version' => TemplateVersion::of(self::getContainer()->get(ShippedTemplates::class)->source('lifecycle'))], $answer['template']);
        self::assertTrue($answer['approvalCoversConflictResolution']);
        self::assertSame('epic/{number}', $answer['epicBranch']);
        self::assertSame(self::getContainer()->get(ShippedTemplates::class)->source('lifecycle')['requirements'], $answer['requirements']);
        self::assertArrayHasKey('githubAppId', $answer);
        self::assertNull($answer['githubAppId'], 'An empty GITHUB_APP_ID reads as no App.');
        $kinds = array_column($answer['kinds'], null, 'kind');
        self::assertSame([
            'product-design', 'product-design-revise', 'design-review', 'tech-design-session', 'tech-design', 'tech-design-revise', 'tech-design-recheck', 'implement', 'breakdown',
            'fix', 'review', 'rebase-stacked', 'sync', 'merge', 'teardown', 'epic-preview', 'sync-epic', 'discovery', 'discovery-revise', 'analysis',
        ], array_column($answer['kinds'], 'kind'));
        self::assertSame('template', $kinds['implement']['origin']);
        self::assertSame(['implement'], $kinds['implement']['rules']);
        self::assertNotContains('The agent pushes as its own GitHub account', $kinds['implement']['checks']);
        self::assertCount(16, $kinds['implement']['checks']);
        foreach (['A preview URL per branch', 'A gate step that uses a shared stack', 'The bridge commands of this kind', 'Each tool that the profile names', 'Each fact that a worker needs'] as $start) {
            $matching = array_values(array_filter($kinds['implement']['checks'], static fn (string $check): bool => str_starts_with($check, $start)));
            self::assertCount(1, $matching, $start);
            self::assertStringContainsString('the actor is', $matching[0], $start);
        }
        self::assertSame(['kind' => 'fix', 'origin' => 'template', 'rules' => ['fix-in-implementation', 'fix-agent-review', 'fix-in-review', 'fix-agent-review-in-review'], 'checks' => self::CODE_CHECKS], $kinds['fix']);
        self::assertSame(['kind' => 'review', 'origin' => 'template', 'rules' => ['agent-review', 'agent-review-in-review'], 'checks' => ['A bridge work entry for the review kind', 'The loupe and loupe-lifecycle skill packages are installed for the agent', 'Conflict check: other review bots. No other reviewer or bot works against the agent review, the fix rounds or the merge rules']], $kinds['review']);
        self::assertSame(['kind' => 'sync', 'origin' => 'template', 'rules' => ['update-behind'], 'checks' => []], $kinds['sync']);
        self::assertSame(['merge-ready', 'merge-ready-epic-child'], $kinds['merge']['rules']);
        self::assertCount(3, $kinds['merge']['checks']);
        self::assertStringStartsWith('Conflict check: branch rules.', $kinds['merge']['checks'][0]);
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

        self::assertSame(['key' => 'simple', 'version' => TemplateVersion::of(self::getContainer()->get(ShippedTemplates::class)->source('simple'))], $answer['template']);
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

    public function test_it_resolves_each_setting_for_each_repository_and_for_the_project(): void
    {
        $project = $this->workflowProject('workflow-get-settings');
        $this->bindLifecycle($project);
        $stored = $this->repository($project, 'acme/stored');
        $file = $this->repository($project, 'acme/file');
        $invalid = $this->repository($project, 'acme/invalid');
        $this->em()->persist(new WorkflowRepositorySetting($this->em()->getReference(Project::class, $project->requireId()) ?? throw new \LogicException('No project.'), $stored, 'merge-approval', SettingValues::encode('agent')));
        $this->em()->flush();
        $this->writer()->apply($project->requireId(), $file, 'blob-a', 'head-a', ['merge-approval' => 'person', 'epic-branch-pattern' => 'a/{number}'], true);
        $this->writer()->apply($project->requireId(), $invalid, 'blob-b', 'head-b', ['merge-approval' => 'maybe', 'epic-branch-pattern' => 'b/{number}'], true);
        $this->actAsMcpTokenBoundTo($project);

        $settings = $this->tool()()['settings'];

        $byRepository = static function (string $key) use ($settings): array {
            $entries = [];
            foreach ($settings as $setting) {
                if ($key === $setting['key']) {
                    $entries[$setting['repository']['path'] ?? '-'] = $setting;
                }
            }

            return $entries;
        };
        $approval = $byRepository('merge-approval');
        self::assertSame(['acme/stored', 'acme/file', 'acme/invalid'], array_keys($approval));
        self::assertSame(['key' => 'merge-approval', 'scope' => 'repository', 'repository' => ['path' => 'acme/stored', 'forge' => 'github'], 'value' => 'agent', 'source' => 'stored', 'warnings' => []], $approval['acme/stored']);
        self::assertSame(['person', 'file'], [$approval['acme/file']['value'], $approval['acme/file']['source']]);
        self::assertNull($approval['acme/invalid']['value']);
        self::assertSame('invalid', $approval['acme/invalid']['source']);
        self::assertCount(1, $approval['acme/invalid']['warnings']);
        $method = $byRepository('epic-child-merge-method');
        self::assertSame(['squash', 'default'], [$method['acme/file']['value'], $method['acme/file']['source']]);
        $pattern = $byRepository('epic-branch-pattern');
        self::assertSame(['-'], array_keys($pattern));
        self::assertNull($pattern['-']['repository']);
        self::assertSame('conflict', $pattern['-']['source']);
        self::assertSame('epic/{number}', $pattern['-']['value']);
        self::assertCount(1, $pattern['-']['warnings']);
        self::assertTrue($byRepository('epic-branches')['-']['value']);
    }

    public function test_epic_branch_follows_the_project_settings_of_the_repository_file(): void
    {
        $project = $this->workflowProject('workflow-get-epic-branch');
        $this->bindLifecycle($project);
        $this->writer()->apply($project->requireId(), $this->repository($project, 'acme/file'), 'blob-a', 'head-a', ['epic-branch-pattern' => 'work/{number}'], true);
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame('work/{number}', $this->tool()()['epicBranch']);

        $off = $this->workflowProject('workflow-get-epic-off');
        $this->bindLifecycle($off);
        $this->writer()->apply($off->requireId(), $this->repository($off, 'acme/off'), 'blob-b', 'head-b', ['epic-branches' => false], true);
        $this->actAsMcpTokenBoundTo($off);

        self::assertNull($this->tool()()['epicBranch']);
    }

    public function test_a_project_without_repositories_lists_its_project_settings_only(): void
    {
        $project = $this->workflowProject('workflow-get-no-repositories');
        $this->bindLifecycle($project);
        $this->actAsMcpTokenBoundTo($project);

        $settings = $this->tool()()['settings'];

        self::assertSame(['epic-branches', 'epic-branch-pattern'], array_column($settings, 'key'));
        self::assertSame([null, null], array_column($settings, 'repository'));
        self::assertSame(['default', 'default'], array_column($settings, 'source'));
    }

    public function test_a_simple_project_lists_only_the_settings_its_template_declares(): void
    {
        $project = $this->workflowProject('workflow-get-simple-settings');
        $this->bindHandler()(new BindWorkflowTemplateCommand($project, 'simple', []));
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame(['epic-branches', 'epic-branch-pattern'], array_column($this->tool()()['settings'], 'key'));
    }

    private function repository(Project $project, string $path): Uuid
    {
        $repository = new ForgeRepository($this->em()->getReference(Project::class, $project->requireId()) ?? throw new \LogicException('No project.'), 'github', 'ext-'.uniqid(), $path, ForgeRepositorySource::Hook);
        $this->em()->persist($repository);
        $this->em()->flush();

        return $repository->id ?? throw new \LogicException('The repository is not flushed.');
    }

    private function writer(): WorkflowSettingsWriter
    {
        $container = self::getContainer();

        return new WorkflowSettingsWriter(
            $container->get(ProjectTemplates::class),
            $container->get(WorkflowSettingOverrideRepository::class),
            $container->get(WorkflowRepositorySettingRepository::class),
            $this->em(),
        );
    }

    private function tool(): WorkflowGetTool
    {
        $tool = self::getContainer()->get(WorkflowGetTool::class);
        self::assertInstanceOf(WorkflowGetTool::class, $tool);

        return $tool;
    }
}
