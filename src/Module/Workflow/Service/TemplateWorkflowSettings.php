<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\ProjectRepositories;
use App\Module\Workflow\Contract\ResolvedSetting;
use App\Module\Workflow\Contract\SettingScope;
use App\Module\Workflow\Contract\SettingSource;
use App\Module\Workflow\Contract\TemplateSetting;
use App\Module\Workflow\Contract\WorkflowSettings;
use App\Module\Workflow\Repository\WorkflowRepositorySettingRepository;
use App\Module\Workflow\Repository\WorkflowSettingOverrideRepository;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(WorkflowSettings::class)]
final readonly class TemplateWorkflowSettings implements WorkflowSettings
{
    public function __construct(
        private ProjectTemplates $templates,
        private WorkflowSettingOverrideRepository $workflowSettingOverrides,
        private WorkflowRepositorySettingRepository $workflowRepositorySettings,
        private ProjectRepositories $repositories,
    ) {
    }

    #[\Override]
    public function declares(Uuid $projectId, string $key): bool
    {
        return null !== $this->templates->of($projectId)->setting($key);
    }

    #[\Override]
    public function forRepository(Uuid $projectId, Uuid $repositoryId, string $key): ResolvedSetting
    {
        $setting = $this->declared($projectId, $key, SettingScope::Repository);
        if (null === $setting) {
            return new ResolvedSetting($key, null, SettingSource::Unset);
        }

        $override = $this->workflowSettingOverrides->findOneByRepositoryIdAndKey($repositoryId, $key);
        if (null !== $override) {
            $value = $override->valid ? SettingValues::decode($setting, $override->value) : null;
            if (null !== $value) {
                return new ResolvedSetting($key, $value, SettingSource::File);
            }

            return new ResolvedSetting($key, null, SettingSource::Invalid, [SettingValues::invalidValue($setting)]);
        }

        $stored = $this->workflowRepositorySettings->findOneByRepositoryIdAndKey($repositoryId, $key);
        $value = SettingValues::decode($setting, $stored?->value);
        if (null !== $value) {
            return new ResolvedSetting($key, $value, SettingSource::Stored);
        }

        return $this->fromDefault($setting);
    }

    #[\Override]
    public function forProject(Uuid $projectId, string $key): ResolvedSetting
    {
        $setting = $this->declared($projectId, $key, SettingScope::Project);
        if (null === $setting) {
            return new ResolvedSetting($key, null, SettingSource::Unset);
        }

        $paths = [];
        foreach ($this->repositories->of($projectId) as $repository) {
            $paths[$repository->id->toRfc4122()] = $repository->path;
        }

        $warnings = [];
        $byPath = [];
        foreach ($this->workflowSettingOverrides->findByProjectIdAndKey($projectId, $key) as $row) {
            $path = $paths[$row->repositoryId->toRfc4122()] ?? null;
            if (null === $path) {
                continue;
            }
            $value = $row->valid ? SettingValues::decode($setting, $row->value) : null;
            if (null === $value) {
                $warnings[] = \sprintf('%s: %s', $path, SettingValues::invalidValue($setting));

                continue;
            }
            $byPath[$path] = $value;
        }

        $distinct = [];
        foreach ($byPath as $value) {
            if (!\in_array($value, $distinct, true)) {
                $distinct[] = $value;
            }
        }
        if (1 === \count($distinct)) {
            return new ResolvedSetting($key, $distinct[0], SettingSource::File, $warnings);
        }
        if (\count($distinct) > 1) {
            $lines = [];
            foreach ($byPath as $path => $value) {
                $lines[] = \sprintf('%s sets %s', $path, \is_bool($value) ? ($value ? 'true' : 'false') : $value);
            }
            $warnings[] = \sprintf('The setting "%s" has different values in the repositories of the project (%s). Loupe uses the value of the project.', $key, implode('; ', $lines));
        }

        $fallback = $this->fromTemplate($projectId, $setting);

        return new ResolvedSetting($key, $fallback->value, \count($distinct) > 1 ? SettingSource::Conflict : $fallback->source, $warnings);
    }

    #[\Override]
    public function projectConflicts(Uuid $projectId, Uuid $repositoryId): array
    {
        $conflicts = [];
        foreach ($this->templates->of($projectId)->settings as $setting) {
            if (SettingScope::Project !== $setting->scope) {
                continue;
            }
            $own = $this->workflowSettingOverrides->findOneByRepositoryIdAndKey($repositoryId, $setting->key);
            if (null === $own || !$own->valid) {
                continue;
            }
            $resolved = $this->forProject($projectId, $setting->key);
            if (SettingSource::Conflict === $resolved->source && [] !== $resolved->warnings) {
                $conflicts[] = array_last($resolved->warnings);
            }
        }

        return $conflicts;
    }

    private function fromTemplate(Uuid $projectId, TemplateSetting $setting): ResolvedSetting
    {
        $value = $this->templates->of($projectId)->settingValues[$setting->key] ?? null;

        return null !== $value ? new ResolvedSetting($setting->key, $value, SettingSource::Template) : $this->fromDefault($setting);
    }

    private function fromDefault(TemplateSetting $setting): ResolvedSetting
    {
        return null === $setting->default
            ? new ResolvedSetting($setting->key, null, SettingSource::Unset)
            : new ResolvedSetting($setting->key, $setting->default, SettingSource::Default);
    }

    private function declared(Uuid $projectId, string $key, SettingScope $scope): ?TemplateSetting
    {
        $setting = $this->templates->of($projectId)->setting($key);
        if (null !== $setting && $scope !== $setting->scope) {
            throw new \LogicException(\sprintf('"%s" is not a setting of the %s scope.', $key, $scope->value));
        }

        return $setting;
    }
}
