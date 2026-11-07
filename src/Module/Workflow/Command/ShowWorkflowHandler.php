<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Module\Workflow\Action\Actions;
use App\Module\Workflow\Contract\BoardColumns;
use App\Module\Workflow\Contract\ColumnView;
use App\Module\Workflow\Contract\ProjectRepositories;
use App\Module\Workflow\Contract\SettingScope;
use App\Module\Workflow\Contract\TemplateSetting;
use App\Module\Workflow\Contract\WorkflowSettings;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use App\Module\Workflow\Template\AppRules;
use App\Module\Workflow\Template\RuleOrigin;
use App\Module\Workflow\Template\Template;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateSource;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

final readonly class ShowWorkflowHandler
{
    public function __construct(
        private TemplateSource $templates,
        private WorkflowSlotLinkRepository $workflowSlotLinks,
        private BoardColumns $boardColumns,
        private AppRules $appRules,
        private Actions $actions,
        private WorkflowSettings $settings,
        private ProjectRepositories $projectRepositories,

        #[Autowire(env: 'default::GITHUB_APP_ID')]
        private ?string $githubAppId = null,
    ) {
    }

    /** @throws TemplateMissing when the project has no workflow */
    public function __invoke(ShowWorkflowCommand $command): WorkflowView
    {
        $project = $command->project;
        $projectId = $project->id ?? throw new \LogicException('The project is not persisted.');
        $template = $this->templates->forProject($projectId);

        $slots = [];
        foreach ($this->workflowSlotLinks->findColumnsBySlot($project) as $slot => $column) {
            if (null !== $column) {
                $slots[(string) $column->id] = $slot;
            }
        }

        $kinds = [];
        foreach ([RuleOrigin::Template, RuleOrigin::App] as $origin) {
            foreach ($template->rules as $rule) {
                $kind = $origin === $rule->origin ? $this->actions->get($rule->lead->key)->workKind($rule->lead->params) : null;
                if (null === $kind) {
                    continue;
                }
                $kinds[$kind] ??= ['origin' => $origin, 'rules' => [], 'checks' => []];
                $kinds[$kind]['rules'][] = $rule->id;
                $kinds[$kind]['checks'] = array_values(array_unique([...$kinds[$kind]['checks'], ...$rule->lead->checks]));
            }
        }

        foreach ($this->appRules->requests() as $request) {
            $kinds[$request->kind] ??= ['origin' => RuleOrigin::App, 'rules' => [], 'checks' => []];
            $kinds[$request->kind]['rules'][] = $request->id;
            $kinds[$request->kind]['checks'] = array_values(array_unique([...$kinds[$request->kind]['checks'], ...$request->checks]));
        }

        return new WorkflowView(
            templateKey: $template->key,
            templateVersion: $template->version,
            columns: array_map(
                static fn (ColumnView $column): WorkflowColumnView => new WorkflowColumnView($column, $slots[(string) $column->id] ?? null),
                $this->boardColumns->forProject($projectId),
            ),
            kinds: array_map(
                static fn (string $kind, array $entry): WorkflowKindView => new WorkflowKindView($kind, $entry['origin'], $entry['rules'], $entry['checks']),
                array_keys($kinds),
                array_values($kinds),
            ),
            approvalCoversConflictResolution: $template->approvalCoversConflictResolution,
            epicBranch: Template::epicBranchPatternOf(
                $this->settings->forProject($projectId, Template::SETTING_EPIC_BRANCHES)->value,
                $this->settings->forProject($projectId, Template::SETTING_EPIC_BRANCH_PATTERN)->value,
            ),
            requirements: $template->requirements,
            githubAppId: '' === $this->githubAppId ? null : $this->githubAppId,
            settings: $this->resolveSettings($projectId, $template->settings),
        );
    }

    /**
     * @param list<TemplateSetting> $declared
     *
     * @return list<WorkflowSettingView>
     */
    private function resolveSettings(Uuid $projectId, array $declared): array
    {
        $repositories = [] === array_filter($declared, static fn (TemplateSetting $setting): bool => SettingScope::Repository === $setting->scope)
            ? []
            : $this->projectRepositories->of($projectId);

        $views = [];
        foreach ($declared as $setting) {
            if (SettingScope::Project === $setting->scope) {
                $resolved = $this->settings->forProject($projectId, $setting->key);
                $views[] = new WorkflowSettingView($setting->key, $setting->scope, null, $resolved->value, $resolved->source, $resolved->warnings);
                continue;
            }
            foreach ($repositories as $repository) {
                $resolved = $this->settings->forRepository($projectId, $repository->id, $setting->key);
                $views[] = new WorkflowSettingView($setting->key, $setting->scope, $repository, $resolved->value, $resolved->source, $resolved->warnings);
            }
        }

        return $views;
    }
}
