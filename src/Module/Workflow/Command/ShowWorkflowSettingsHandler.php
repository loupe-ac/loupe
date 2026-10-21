<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Module\Workflow\Action\Actions;
use App\Module\Workflow\Action\MissingAction;
use App\Module\Workflow\Expression\AllOf;
use App\Module\Workflow\Expression\AnyOf;
use App\Module\Workflow\Expression\ConditionLeaf;
use App\Module\Workflow\Expression\Expression;
use App\Module\Workflow\Expression\MissingActionLeaf;
use App\Module\Workflow\Expression\MissingConditionLeaf;
use App\Module\Workflow\Expression\NamedFact;
use App\Module\Workflow\Expression\Not;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use App\Module\Workflow\Template\ManualMove;
use App\Module\Workflow\Template\ManualMoveActor;
use App\Module\Workflow\Template\Rule;
use App\Module\Workflow\Template\RuleOrigin;
use App\Module\Workflow\Template\Slot;
use App\Module\Workflow\Template\Template;
use App\Module\Workflow\Template\TemplateCardType;
use App\Module\Workflow\Template\TemplateMarker;
use App\Module\Workflow\Template\TemplateSource;

final readonly class ShowWorkflowSettingsHandler
{
    public function __construct(
        private WorkflowBindingRepository $workflowBindings,
        private WorkflowSlotLinkRepository $workflowSlotLinks,
        private TemplateSource $templates,
        private Actions $actions,
    ) {
    }

    public function __invoke(ShowWorkflowSettingsCommand $command): WorkflowSettingsView
    {
        $project = $command->project;
        $projectId = $project->id ?? throw new \LogicException('The project is not persisted.');
        $binding = $this->workflowBindings->findOneByProjectId($projectId);
        if (null === $binding) {
            return new WorkflowSettingsView($project, null);
        }

        $template = $this->templates->forProject($projectId);
        $rules = fn (RuleOrigin $origin): array => array_map(
            fn (Rule $rule): WorkflowRuleView => $this->rule($template, $rule),
            array_values(array_filter($template->rules, static fn (Rule $rule): bool => $origin === $rule->origin)),
        );
        $columns = $this->workflowSlotLinks->findColumnsBySlot($project);

        return new WorkflowSettingsView($project, new BoundWorkflowView(
            key: $template->key,
            labelKey: \sprintf('workflow.template.%s.label', $template->key),
            descriptionKey: \sprintf('workflow.template.%s.description', $template->key),
            version: $template->version,
            boundAt: $binding->boundAt,
            types: array_map(
                static fn (TemplateCardType $type): WorkflowCardTypeView => new WorkflowCardTypeView($type->key, $type->label, $type->tone->value, $type->children, $type->lane, $type->key === $template->defaultType),
                $template->types,
            ),
            slots: array_map(
                static fn (Slot $slot): WorkflowSlotView => new WorkflowSlotView($slot->key, $slot->label, ($columns[$slot->key] ?? null)?->label),
                $template->slots,
            ),
            rules: $rules(RuleOrigin::Template),
            appRules: $rules(RuleOrigin::App),
            manualMoves: array_map(
                static fn (ManualMove $move): WorkflowManualMoveView => new WorkflowManualMoveView(
                    self::placeKey($template, $move->from),
                    self::placeKey($template, $move->to),
                    match ($move->by) {
                        null => 'workflow.settings.moves.by.anyone',
                        ManualMoveActor::ParentRun => 'workflow.settings.moves.by.parent_run',
                    },
                ),
                $template->manualMoves,
            ),
            markers: array_map(static fn (TemplateMarker $marker): WorkflowMarkerView => self::marker($template, $marker), $template->markers),
            backoffMinutes: $template->backoffMinutes,
            workTimeoutMinutes: $template->workTimeoutMinutes,
            workFailedBackoffMinutes: $template->onWorkFailed?->backoffMinutes,
        ));
    }

    private static function marker(Template $template, TemplateMarker $marker): WorkflowMarkerView
    {
        return new WorkflowMarkerView(
            key: $marker->key,
            labelKey: $marker->label,
            kind: $marker->kind->value,
            slotLabelKeys: array_map(static fn (string $slot): string => self::placeKey($template, $slot), $marker->slots),
            fallback: $marker->fallback,
            whenGroups: self::groups($marker->when),
            missingConditions: array_values(array_unique($marker->when->missingKeys())),
        );
    }

    private function rule(Template $template, Rule $rule): WorkflowRuleView
    {
        $description = $this->actions->get($rule->then->key)->describe($rule->then->params);
        $expressions = array_values(array_filter([$rule->when, $rule->then->until, $rule->then->refill, $rule->then->cancelWhen]));
        $missing = array_merge(...array_map(static fn (Expression $expression): array => $expression->missingKeys(), $expressions));

        return new WorkflowRuleView(
            id: $rule->id,
            appliesToKey: self::placeKey($template, $rule->slot ?? '*'),
            actionKey: $description->settingsKey,
            targetKey: null === $description->settingsTarget ? null : self::placeKey($template, $description->settingsTarget),
            kind: $description->settingsDetail,
            whenGroups: self::groups($rule->when),
            untilGroups: null === $rule->then->until ? [] : self::groups($rule->then->until),
            refillGroups: null === $rule->then->refill ? [] : self::groups($rule->then->refill),
            missingConditions: array_values(array_unique($missing)),
            missingAction: MissingAction::KEY === $rule->then->key ? (string) $rule->then->params['name'] : null,
            cancelWhenGroups: null === $rule->then->cancelWhen ? [] : self::groups($rule->then->cancelWhen),
        );
    }

    /** @return list<WorkflowConditionGroupView> */
    private static function groups(Expression $expression): array
    {
        $groups = [];
        foreach (self::conditions($expression, false, null) as [$leaf, $negated, $fact]) {
            $condition = new WorkflowConditionView($leaf->condition::key(), $negated, self::params($leaf->params), $fact);
            $groups[$leaf->condition::source()][serialize($condition)] = $condition;
        }

        return array_map(
            static fn (string $source, array $conditions): WorkflowConditionGroupView => new WorkflowConditionGroupView($source, array_values($conditions)),
            array_keys($groups),
            array_values($groups),
        );
    }

    /**
     * A missing condition yields nothing here, because missingKeys() lists it.
     *
     * @return list<array{ConditionLeaf, bool, ?string}> each leaf with whether a `not` above it negates it, and the name of the outermost named fact above it, in template order
     */
    private static function conditions(Expression $expression, bool $negated, ?string $fact): array
    {
        return match (true) {
            $expression instanceof ConditionLeaf => [[$expression, $negated, $fact]],
            $expression instanceof NamedFact => self::conditions($expression->expression, $negated, $fact ?? $expression->name),
            $expression instanceof Not => self::conditions($expression->inner, !$negated, $fact),
            $expression instanceof AllOf, $expression instanceof AnyOf => array_merge(...array_map(static fn (Expression $child): array => self::conditions($child, $negated, $fact), $expression->children)),
            $expression instanceof MissingConditionLeaf, $expression instanceof MissingActionLeaf => [],
            default => throw new \LogicException(\sprintf('The settings page cannot list a %s.', $expression::class)),
        };
    }

    /** @param array<string, mixed> $params */
    private static function params(array $params): string
    {
        return implode(', ', array_map(
            static fn (string $name, mixed $value): string => $name.': '.(\is_string($value) ? $value : json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)),
            array_keys($params),
            array_values($params),
        ));
    }

    /** @param string $place a slot key, '@backlog', '@terminal' or '*' */
    private static function placeKey(Template $template, string $place): string
    {
        return match ($place) {
            '@backlog' => 'workflow.settings.where.backlog',
            '@terminal' => 'workflow.settings.where.any_terminal',
            '*' => 'workflow.settings.where.any',
            default => ($template->slot($place) ?? throw new \LogicException(\sprintf('The template has no slot "%s".', $place)))->label,
        };
    }
}
