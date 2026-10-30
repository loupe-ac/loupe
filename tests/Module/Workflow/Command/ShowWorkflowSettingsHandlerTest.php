<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Command;

use App\Module\Project\Entity\Project;
use App\Module\Workflow\Action\Actions;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Command\BoundWorkflowView;
use App\Module\Workflow\Command\ShowWorkflowSettingsCommand;
use App\Module\Workflow\Command\ShowWorkflowSettingsHandler;
use App\Module\Workflow\Command\WorkflowCardTypeView;
use App\Module\Workflow\Command\WorkflowConditionGroupView;
use App\Module\Workflow\Command\WorkflowConditionView;
use App\Module\Workflow\Command\WorkflowManualMoveView;
use App\Module\Workflow\Command\WorkflowMetricView;
use App\Module\Workflow\Command\WorkflowRuleActionView;
use App\Module\Workflow\Command\WorkflowRuleView;
use App\Module\Workflow\Command\WorkflowSettingsView;
use App\Module\Workflow\Command\WorkflowSlotView;
use App\Module\Workflow\Entity\WorkflowBinding;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use App\Module\Workflow\Template\AppRules;
use App\Module\Workflow\Template\ProjectTemplateCopy;
use App\Module\Workflow\Template\TemplateParser;
use App\Tests\Module\Workflow\Template\AppRulesTest;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ShowWorkflowSettingsHandlerTest extends KernelTestCase
{
    use WorkflowProjects;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_an_unbound_project_has_no_template(): void
    {
        $project = $this->workflowProject('workflow-settings-unbound');

        $view = $this->show($project);

        self::assertSame($project->id?->toRfc4122(), $view->project->id?->toRfc4122());
        self::assertNull($view->template);
    }

    public function test_a_lifecycle_project_shows_its_slots_rules_moves_and_timings(): void
    {
        $project = $this->workflowProject('workflow-settings-lifecycle');
        $binding = $this->bindLifecycle($project);
        $this->em()->clear();

        $template = $this->show($project)->template;

        self::assertInstanceOf(BoundWorkflowView::class, $template);
        self::assertSame('lifecycle', $template->key);
        self::assertSame('workflow.template.lifecycle.label', $template->labelKey);
        self::assertSame('workflow.template.lifecycle.description', $template->descriptionKey);
        self::assertSame(30, $template->version);
        self::assertSame($binding->boundAt->format('Y-m-d H:i:s'), $template->boundAt->format('Y-m-d H:i:s'));
        self::assertEquals([
            new WorkflowSlotView('next', 'workflow.slot.next', 'board.card.status.next'),
            new WorkflowSlotView('product-design', 'workflow.slot.product_design', 'product-design'),
            new WorkflowSlotView('tech-design', 'workflow.slot.tech_design', 'tech-design'),
            new WorkflowSlotView('implementation', 'workflow.slot.implementation', 'board.card.status.in-progress'),
            new WorkflowSlotView('in-review', 'workflow.slot.in_review', 'in-review'),
        ], $template->slots);

        $rules = [];
        foreach ($template->rules as $rule) {
            $rules[$rule->id] = $rule;
        }
        self::assertEquals(new WorkflowRuleView('product-design-approved', 'workflow.slot.product_design', [new WorkflowRuleActionView('workflow.settings.action.move', 'workflow.slot.tech_design', null)], [new WorkflowConditionGroupView('workflow.source.board', [new WorkflowConditionView('card.document.approved', false, 'tag: product-design'), new WorkflowConditionView('card.document.changes_requested', true, 'tag: product-design')])], [], [], []), $rules['product-design-approved']);
        self::assertEquals(new WorkflowRuleView('implement', 'workflow.slot.implementation', [new WorkflowRuleActionView('workflow.settings.action.request', null, 'implement')], [new WorkflowConditionGroupView('workflow.source.board', [new WorkflowConditionView('card.type', true, 'types: ["epic"]')]), new WorkflowConditionGroupView('workflow.source.forge', [new WorkflowConditionView('card.pr.linked', true, '')])], [], [], []), $rules['implement']);
        self::assertEquals(new WorkflowRuleView('rebase-stacked', 'workflow.slot.in_review', [new WorkflowRuleActionView('workflow.settings.action.forge_write', null, 'change-base')], [new WorkflowConditionGroupView('workflow.source.forge', [new WorkflowConditionView('pr.open', false, ''), new WorkflowConditionView('pr.stacked', false, ''), new WorkflowConditionView('pr.parent_merged', false, '')])], [], [], []), $rules['rebase-stacked']);
        self::assertSame(['product-design-undo', 'tech-design-undo', 'product-design-session'], array_map(static fn (WorkflowRuleView $rule): string => $rule->id, \array_slice($template->rules, 0, 3)));
        self::assertEquals([
            new WorkflowRuleActionView('workflow.settings.action.stop_work', null, null),
            new WorkflowRuleActionView('workflow.settings.action.request_changes', null, null),
            new WorkflowRuleActionView('workflow.settings.action.move', 'workflow.slot.product_design', null),
        ], $rules['product-design-undo']->actions);
        self::assertSame('workflow.settings.where.any', $rules['product-design-undo']->appliesToKey);
        self::assertEquals([
            new WorkflowConditionGroupView('workflow.source.board', [new WorkflowConditionView('card.document.linked', true, 'tag: product-design')]),
            new WorkflowConditionGroupView('workflow.source.bridge', [new WorkflowConditionView('card.run.session_open', true, '')]),
        ], $rules['product-design-session']->whenGroups);
        self::assertEquals([
            new WorkflowConditionGroupView('workflow.source.board', [new WorkflowConditionView('card.document.linked', true, 'tag: tech-design')]),
        ], $rules['tech-design-write']->whenGroups);
        self::assertEquals([
            new WorkflowConditionGroupView('workflow.source.board', [new WorkflowConditionView('card.in_slot', true, 'slot: @terminal'), new WorkflowConditionView('card.children.finished', false, ''), new WorkflowConditionView('card.type', true, 'types: ["epic"]')]),
            new WorkflowConditionGroupView('workflow.source.forge', [new WorkflowConditionView('card.pr.all_finished_one_merged', false, '')]),
            new WorkflowConditionGroupView('workflow.source.bridge', [new WorkflowConditionView('card.run.worker_active', true, '')]),
        ], $rules['merged']->whenGroups);
        self::assertEquals([
            new WorkflowConditionGroupView('workflow.source.forge', [new WorkflowConditionView('pr.open', false, ''), new WorkflowConditionView('pr.behind', false, ''), new WorkflowConditionView('pr.approval_check_passed', false, ''), new WorkflowConditionView('pr.base_is_epic_branch', false, '')]),
        ], $rules['update-behind']->whenGroups);
        self::assertEquals([
            new WorkflowConditionGroupView('workflow.source.board', [new WorkflowConditionView('card.document.approved', false, 'tag: tech-design'), new WorkflowConditionView('card.document.changes_requested', true, 'tag: tech-design'), new WorkflowConditionView('card.blocker.open', true, ''), new WorkflowConditionView('card.blocker.closed', true, '')]),
            new WorkflowConditionGroupView('workflow.source.bridge', [new WorkflowConditionView('card.run.finished_with', false, 'kind: tech-design-recheck, reason: done')]),
        ], $rules['tech-design-approved']->whenGroups);

        self::assertEquals([
            new WorkflowRuleView('discovery', 'workflow.settings.where.backlog', [new WorkflowRuleActionView('workflow.settings.action.request', null, 'discovery')], [new WorkflowConditionGroupView('workflow.source.readiness', [new WorkflowConditionView('card.discovery.requested', false, '')])], [], [], []),
            new WorkflowRuleView('discovery-revise', 'workflow.settings.where.backlog', [new WorkflowRuleActionView('workflow.settings.action.request', null, 'discovery-revise')], [new WorkflowConditionGroupView('workflow.source.readiness', [new WorkflowConditionView('card.discovery.revising', false, '')])], [], [], []),
            new WorkflowRuleView('discovery-approved', 'workflow.settings.where.backlog', [new WorkflowRuleActionView('workflow.settings.action.move', 'workflow.settings.where.any_terminal', null)], [new WorkflowConditionGroupView('workflow.source.readiness', [new WorkflowConditionView('card.discovery.approved', false, '')])], [], [], []),
            new WorkflowRuleView('post-widget-review', 'workflow.settings.where.any', [new WorkflowRuleActionView('workflow.settings.action.forge_write', null, 'post-review')], [new WorkflowConditionGroupView('workflow.source.board', [new WorkflowConditionView('card.site_review.verdict_unsent', false, '')])], [], [], []),
            new WorkflowRuleView('sync-site-review-check', 'workflow.settings.where.any', [new WorkflowRuleActionView('workflow.settings.action.forge_write', null, 'site-review-check')], [new WorkflowConditionGroupView('workflow.source.board', [new WorkflowConditionView('card.site_review.check_stale', false, '')])], [], [], []),
            new WorkflowRuleView('sync-agent-review-check', 'workflow.settings.where.any', [new WorkflowRuleActionView('workflow.settings.action.forge_write', null, 'agent-review-check')], [new WorkflowConditionGroupView('workflow.source.agent_review', [new WorkflowConditionView('agent_review.unposted', false, '')])], [], [], []),
            new WorkflowRuleView('comment-agent-review', 'workflow.settings.where.any', [new WorkflowRuleActionView('workflow.settings.action.forge_write', null, 'comment agent-review')], [new WorkflowConditionGroupView('workflow.source.agent_review', [new WorkflowConditionView('agent_review.uncommented', false, '')])], [], [], []),
        ], $template->appRules);
        self::assertEquals(new WorkflowManualMoveView('workflow.settings.where.backlog', 'workflow.slot.next', 'workflow.settings.moves.by.anyone'), $template->manualMoves[0]);
        self::assertCount(9, $template->manualMoves);
        self::assertSame([10, 60, 360], $template->backoffMinutes);
        self::assertSame(120, $template->workTimeoutMinutes);
        self::assertSame([2, 3, 5], $template->workFailedBackoffMinutes);
        self::assertEquals([
            new WorkflowCardTypeView('feature', 'board.card.type.feature', 'lime', false, false, true),
            new WorkflowCardTypeView('bug', 'board.card.type.bug', 'amber', false, false, false),
            new WorkflowCardTypeView('security', 'board.card.type.security', 'red', false, false, false),
            new WorkflowCardTypeView('tooling', 'board.card.type.tooling', 'neutral', false, false, false),
            new WorkflowCardTypeView('docs', 'board.card.type.docs', 'green', false, false, false),
            new WorkflowCardTypeView('idea', 'board.card.type.idea', 'purple', false, false, false),
            new WorkflowCardTypeView('epic', 'board.card.type.epic', 'blue', true, true, false),
        ], $template->types);
    }

    public function test_the_metrics_of_the_template_are_listed_with_what_each_reads(): void
    {
        $project = $this->workflowProject('workflow-settings-metrics');
        $binding = $this->bindHandler()(new BindWorkflowTemplateCommand($project, 'simple', []));
        $stored = $this->em()->find(WorkflowBinding::class, $binding->id) ?? throw new \LogicException('The binding is gone.');
        $stored->definition['metrics'] = [
            ['key' => 'conflicts', 'label' => 'Conflicts', 'description' => 'The conflicts of a card.', 'count' => ['reason' => 'conflict'], 'splitBy' => 'reason'],
            ['key' => 'delivery-rate', 'label' => 'Delivery rate', 'description' => 'The share that merged.', 'rate' => ['outcome' => 'merged']],
            ['key' => 'needed-fix', 'label' => 'Needed a fix', 'description' => 'The share with a failed check.', 'rate' => ['any' => ['reason' => 'checks-failed']]],
            ['key' => 'hours-to-pr-merge', 'label' => 'Hours to pull request merge', 'description' => 'The hours to merge.', 'duration' => ['from' => ['last' => 'pr-opened'], 'to' => 'pr-merged']],
            ['key' => 'hours-in-backlog', 'label' => 'Hours in backlog', 'description' => 'The hours in backlog.', 'duration' => ['from' => ['arrived' => '@backlog'], 'to' => ['left' => '@backlog']]],
        ];
        $this->em()->flush();
        $this->em()->clear();

        $template = $this->show($project)->template;

        self::assertInstanceOf(BoundWorkflowView::class, $template);
        self::assertEquals([
            new WorkflowMetricView('conflicts', 'Conflicts', 'The conflicts of a card.', 'count', 'Work requests with reason conflict, split by reason'),
            new WorkflowMetricView('delivery-rate', 'Delivery rate', 'The share that merged.', 'rate', 'Finished cards that a move with outcome merged ended'),
            new WorkflowMetricView('needed-fix', 'Needed a fix', 'The share with a failed check.', 'rate', 'Finished cards with at least one work request with reason checks-failed'),
            new WorkflowMetricView('hours-to-pr-merge', 'Hours to pull request merge', 'The hours to merge.', 'duration', 'From the last time the pull request opened to the pull request merged'),
            new WorkflowMetricView('hours-in-backlog', 'Hours in backlog', 'The hours in backlog.', 'duration', 'From the card arrived in the Backlog to the card left the Backlog'),
        ], $template->metrics);
    }

    public function test_the_simple_template_lists_its_two_metrics(): void
    {
        $project = $this->workflowProject('workflow-settings-simple-metrics');
        $this->bindHandler()(new BindWorkflowTemplateCommand($project, 'simple', []));
        $this->em()->clear();

        $template = $this->show($project)->template;

        self::assertInstanceOf(BoundWorkflowView::class, $template);
        self::assertSame(['merge-rate', 'hours-to-merge'], array_map(static fn (WorkflowMetricView $metric): string => $metric->key, $template->metrics));
    }

    public function test_the_rules_the_app_adds_are_listed_apart_from_the_template_rules(): void
    {
        $project = $this->workflowProject('workflow-settings-app-rules');
        $this->bindHandler()(new BindWorkflowTemplateCommand($project, 'simple', []));
        $this->em()->clear();
        $parser = $this->service(TemplateParser::class);
        $bindings = $this->service(WorkflowBindingRepository::class);
        $handler = new ShowWorkflowSettingsHandler(
            $bindings,
            $this->service(WorkflowSlotLinkRepository::class),
            new ProjectTemplateCopy($bindings, $parser, new AppRules($parser, AppRulesTest::FIXTURE)),
            $this->service(Actions::class),
            $this->service(TranslatorInterface::class),
        );

        $template = $handler(new ShowWorkflowSettingsCommand($this->em()->find(Project::class, $project->id) ?? throw new \LogicException('The project is gone.')))->template;

        self::assertInstanceOf(BoundWorkflowView::class, $template);
        self::assertSame(['merged', 'teardown'], array_map(static fn (WorkflowRuleView $rule): string => $rule->id, $template->rules));
        self::assertEquals([
            new WorkflowRuleView('app-groom', 'workflow.settings.where.backlog', [new WorkflowRuleActionView('workflow.settings.action.request', null, 'groom')], [new WorkflowConditionGroupView('workflow.source.board', [new WorkflowConditionView('card.blocker.open', true, '')])], [], [], []),
            new WorkflowRuleView('app-tidy', 'workflow.settings.where.any', [new WorkflowRuleActionView('workflow.settings.action.release', null, null)], [], [], [], []),
        ], $template->appRules);
    }

    public function test_a_slot_whose_column_was_deleted_has_no_column(): void
    {
        $project = $this->workflowProject('workflow-settings-deleted-column');
        $this->bindLifecycle($project);
        $this->em()->remove($this->column($project, 'tech-design'));
        $this->em()->flush();
        $this->em()->clear();

        $slots = ($this->show($project)->template ?? throw new \LogicException('The project is not bound.'))->slots;

        $columns = array_combine(
            array_map(static fn (WorkflowSlotView $slot): string => $slot->key, $slots),
            array_map(static fn (WorkflowSlotView $slot): ?string => $slot->columnLabel, $slots),
        );
        self::assertNull($columns['tech-design']);
        self::assertSame('product-design', $columns['product-design']);
    }

    public function test_the_simple_template_reads_its_column_flags(): void
    {
        $project = $this->workflowProject('workflow-settings-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($project, 'simple', []));
        $this->em()->clear();

        $template = $this->show($project)->template;

        self::assertInstanceOf(BoundWorkflowView::class, $template);
        self::assertSame([], $template->slots);
        self::assertEquals([
            new WorkflowRuleView('merged', 'workflow.settings.where.any', [new WorkflowRuleActionView('workflow.settings.action.move', 'workflow.settings.where.any_terminal', null)], [new WorkflowConditionGroupView('workflow.source.board', [new WorkflowConditionView('card.in_slot', true, 'slot: @terminal'), new WorkflowConditionView('card.children.finished', false, '')]), new WorkflowConditionGroupView('workflow.source.forge', [new WorkflowConditionView('card.pr.all_finished_one_merged', false, '')])], [], [], []),
            new WorkflowRuleView('teardown', 'workflow.settings.where.any_terminal', [new WorkflowRuleActionView('workflow.settings.action.request', null, 'teardown')], [], [], [], []),
        ], $template->rules);
        self::assertEquals([new WorkflowManualMoveView('workflow.settings.where.any', 'workflow.settings.where.any', 'workflow.settings.moves.by.anyone')], $template->manualMoves);
    }

    public function test_a_named_fact_shows_its_conditions_with_the_name_of_the_outermost_fact(): void
    {
        $project = $this->workflowProject('workflow-settings-named-fact');
        $this->em()->persist(new WorkflowBinding($project, 'test', 1, [
            'key' => 'test',
            'version' => 1,
            'defaultType' => 'feature',
            'types' => [
                ['key' => 'feature', 'label' => 'board.card.type.feature', 'tone' => 'lime'],
                ['key' => 'epic', 'label' => 'board.card.type.epic', 'tone' => 'blue', 'capabilities' => ['children', 'lane']],
            ],
            'slots' => [],
            'manualMoves' => [],
            'backoffMinutes' => [10],
            'workTimeoutMinutes' => 120,
            'facts' => [
                'linked' => ['card.parent.exists' => []],
                'linked-with-children' => ['all' => [['fact.linked' => []], ['not' => ['card.children.exist' => []]]]],
            ],
            'rules' => [
                ['id' => 'work', 'when' => ['all' => [['pr.open' => []], ['fact.linked-with-children' => []]]], 'then' => ['request' => ['kind' => 'work']]],
            ],
        ]));
        $this->em()->flush();
        $this->em()->clear();

        $template = $this->show($project)->template;

        self::assertInstanceOf(BoundWorkflowView::class, $template);
        self::assertEquals([
            new WorkflowConditionGroupView('workflow.source.forge', [new WorkflowConditionView('pr.open', false, '')]),
            new WorkflowConditionGroupView('workflow.source.board', [new WorkflowConditionView('card.parent.exists', false, '', 'linked-with-children'), new WorkflowConditionView('card.children.exist', true, '', 'linked-with-children')]),
        ], $template->rules[0]->whenGroups);
    }

    public function test_a_stored_copy_that_names_a_condition_this_instance_lacks_still_shows(): void
    {
        $project = $this->workflowProject('workflow-settings-missing-condition');
        $this->em()->persist(new WorkflowBinding($project, 'test', 1, [
            'key' => 'test',
            'version' => 1,
            'defaultType' => 'feature',
            'types' => [
                ['key' => 'feature', 'label' => 'board.card.type.feature', 'tone' => 'lime'],
                ['key' => 'bug', 'label' => 'board.card.type.bug', 'tone' => 'amber'],
                ['key' => 'security', 'label' => 'board.card.type.security', 'tone' => 'red'],
                ['key' => 'tooling', 'label' => 'board.card.type.tooling', 'tone' => 'neutral'],
                ['key' => 'docs', 'label' => 'board.card.type.docs', 'tone' => 'green'],
                ['key' => 'idea', 'label' => 'board.card.type.idea', 'tone' => 'purple'],
                ['key' => 'epic', 'label' => 'board.card.type.epic', 'tone' => 'blue', 'capabilities' => ['children', 'lane']],
            ],
            'slots' => [],
            'manualMoves' => [],
            'backoffMinutes' => [10],
            'workTimeoutMinutes' => 120,
            'rules' => [
                ['id' => 'gone', 'when' => ['card.gone' => []], 'then' => ['request' => ['kind' => 'gone']]],
                ['id' => 'work', 'when' => ['all' => []], 'then' => ['request' => ['kind' => 'work']]],
                ['id' => 'later', 'when' => ['all' => [['card.parent.exists' => []]]], 'then' => ['jump' => []]],
                ['id' => 'paused', 'when' => ['all' => [['card.parent.exists' => []], ['card.parent.exists' => []], ['not' => ['any' => [['card.parent.exists' => []], ['not' => ['card.children.exist' => []]]]]]]], 'then' => ['pause' => ['reason' => 'held', 'until' => ['all' => [['pr.lost' => []], ['card.parent.exists' => []], ['pr.open' => []]]]]]],
            ],
        ]));
        $this->em()->flush();
        $this->em()->clear();

        $template = $this->show($project)->template;

        self::assertInstanceOf(BoundWorkflowView::class, $template);
        self::assertSame(['gone', 'work', 'later', 'paused'], array_map(static fn (WorkflowRuleView $rule): string => $rule->id, $template->rules));
        self::assertSame(['card.gone'], $template->rules[0]->missingConditions);
        self::assertSame([], $template->rules[0]->whenGroups);
        self::assertSame([], $template->rules[1]->missingConditions);
        self::assertSame([], $template->rules[1]->whenGroups);
        self::assertSame('jump', $template->rules[2]->missingAction);
        self::assertSame('workflow.settings.action.missing', $template->rules[2]->actions[0]->actionKey);
        self::assertNull($template->rules[1]->missingAction);
        self::assertSame(['pr.lost'], $template->rules[3]->missingConditions);
        self::assertEquals([new WorkflowConditionGroupView('workflow.source.board', [new WorkflowConditionView('card.parent.exists', false, ''), new WorkflowConditionView('card.parent.exists', true, ''), new WorkflowConditionView('card.children.exist', false, '')])], $template->rules[3]->whenGroups);
        self::assertEquals([
            new WorkflowConditionGroupView('workflow.source.board', [new WorkflowConditionView('card.parent.exists', false, '')]),
            new WorkflowConditionGroupView('workflow.source.forge', [new WorkflowConditionView('pr.open', false, '')]),
        ], $template->rules[3]->untilGroups);
        self::assertSame([], $template->rules[0]->untilGroups);
        self::assertSame([], $template->rules[0]->refillGroups);
        self::assertNull($template->workFailedBackoffMinutes);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    private function show(Project $project): WorkflowSettingsView
    {
        $handler = self::getContainer()->get(ShowWorkflowSettingsHandler::class);
        self::assertInstanceOf(ShowWorkflowSettingsHandler::class, $handler);
        $project = $this->em()->find(Project::class, $project->id) ?? throw new \LogicException('The project is gone.');

        return $handler(new ShowWorkflowSettingsCommand($project));
    }
}
