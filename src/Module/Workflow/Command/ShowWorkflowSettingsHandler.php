<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Module\Workflow\Action\Actions;
use App\Module\Workflow\Action\MissingAction;
use App\Module\Workflow\Contract\CountFilter;
use App\Module\Workflow\Contract\DurationEvent;
use App\Module\Workflow\Contract\MetricType;
use App\Module\Workflow\Contract\TemplateMetric;
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
use App\Module\Workflow\Template\ActionCall;
use App\Module\Workflow\Template\ManualMove;
use App\Module\Workflow\Template\ManualMoveActor;
use App\Module\Workflow\Template\Rule;
use App\Module\Workflow\Template\RuleOrigin;
use App\Module\Workflow\Template\Slot;
use App\Module\Workflow\Template\Template;
use App\Module\Workflow\Template\TemplateCardType;
use App\Module\Workflow\Template\TemplateMarker;
use App\Module\Workflow\Template\TemplateSource;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ShowWorkflowSettingsHandler
{
    public function __construct(
        private WorkflowBindingRepository $workflowBindings,
        private WorkflowSlotLinkRepository $workflowSlotLinks,
        private TemplateSource $templates,
        private Actions $actions,
        private TranslatorInterface $translator,
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
                        ManualMoveActor::Session => 'workflow.settings.moves.by.session',
                    },
                ),
                $template->manualMoves,
            ),
            markers: array_map(static fn (TemplateMarker $marker): WorkflowMarkerView => self::marker($template, $marker), $template->markers),
            metrics: array_map(fn (TemplateMetric $metric): WorkflowMetricView => $this->metric($template, $metric), $template->metrics),
            backoffMinutes: $template->backoffMinutes,
            workFailedBackoffMinutes: $template->onWorkFailed?->backoffMinutes,
        ));
    }

    private function metric(Template $template, TemplateMetric $metric): WorkflowMetricView
    {
        return new WorkflowMetricView($metric->key, $metric->label, $metric->description, $metric->type->value, $this->reads($template, $metric));
    }

    private function reads(Template $template, TemplateMetric $metric): string
    {
        $filter = null === $metric->filter ? '' : $this->filter($metric->filter);

        return match ($metric->type) {
            MetricType::Count => null === $metric->splitBy
                ? $this->translator->trans('workflow.settings.metrics.reads.count', ['%filter%' => $filter])
                : $this->translator->trans('workflow.settings.metrics.reads.count_split', ['%filter%' => $filter, '%field%' => $metric->splitBy]),
            MetricType::Rate => null === $metric->outcome
                ? $this->translator->trans('workflow.settings.metrics.reads.rate_any', ['%filter%' => $filter])
                : $this->translator->trans('workflow.settings.metrics.reads.rate_outcome', ['%outcome%' => $metric->outcome]),
            MetricType::Duration => $this->translator->trans('workflow.settings.metrics.reads.duration', [
                '%from%' => $this->event($template, $metric->from, $metric->fromLast),
                '%to%' => $this->event($template, $metric->to, false),
            ]),
        };
    }

    private function filter(CountFilter $filter): string
    {
        $parts = [];
        foreach (CountFilter::FIELDS as $field) {
            if (null !== $filter->{$field}) {
                $parts[] = $field.' '.$filter->{$field};
            }
        }

        return implode(', ', $parts);
    }

    private function event(Template $template, ?DurationEvent $event, bool $last): string
    {
        if (null === $event) {
            throw new \LogicException('A duration metric has a start and an end.');
        }

        $name = match ($event->name) {
            DurationEvent::ARRIVED, DurationEvent::LEFT => $this->translator->trans('workflow.settings.metrics.event.'.$event->name, ['%slot%' => $this->translator->trans(self::placeKey($template, $event->slot ?? throw new \LogicException('A slot event names its slot.')))]),
            default => $this->translator->trans('workflow.settings.metrics.event.'.$event->name),
        };

        return $last ? $this->translator->trans('workflow.settings.metrics.event.last', ['%event%' => $name]) : $name;
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
        $actions = array_map(function (ActionCall $call) use ($template): WorkflowRuleActionView {
            $description = $this->actions->get($call->key)->describe($call->params);

            return new WorkflowRuleActionView(
                actionKey: $description->settingsKey,
                targetKey: null === $description->settingsTarget ? null : self::placeKey($template, $description->settingsTarget),
                kind: $description->settingsDetail,
            );
        }, $rule->then);
        $expressions = array_values(array_filter([$rule->when, ...array_merge(...array_map(static fn (ActionCall $call): array => [$call->until, $call->refill, $call->cancelWhen], $rule->then))]));
        $missing = array_merge(...array_map(static fn (Expression $expression): array => $expression->missingKeys(), $expressions));

        return new WorkflowRuleView(
            id: $rule->id,
            appliesToKey: self::placeKey($template, $rule->slot ?? '*'),
            actions: $actions,
            whenGroups: self::groups($rule->when),
            untilGroups: null === $rule->lead->until ? [] : self::groups($rule->lead->until),
            refillGroups: null === $rule->lead->refill ? [] : self::groups($rule->lead->refill),
            missingConditions: array_values(array_unique($missing)),
            missingAction: MissingAction::KEY === $rule->lead->key ? (string) $rule->lead->params['name'] : null,
            cancelWhenGroups: null === $rule->lead->cancelWhen ? [] : self::groups($rule->lead->cancelWhen),
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
