<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\RepositoryWorkflowSettingsWriter;
use App\Module\Workflow\Contract\WorkflowSettings;
use App\Module\Workflow\Entity\WorkflowRepositorySetting;
use App\Module\Workflow\Entity\WorkflowSettingOverride;
use App\Module\Workflow\Repository\WorkflowRepositorySettingRepository;
use App\Module\Workflow\Repository\WorkflowSettingOverrideRepository;
use App\Module\Workflow\Service\ProjectTemplates;
use App\Module\Workflow\Service\SettingValues;
use App\Module\Workflow\Service\WorkflowSettingsWriter;
use App\Module\Workflow\Template\ProjectTemplateCopy;
use App\Module\Workflow\Template\TemplateSource;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/** A bound Lifecycle project with repositories, and helpers to write the setting rows. */
abstract class WorkflowSettingsTestCase extends KernelTestCase
{
    use WorkflowProjects {
        em as protected;
        workflowProject as protected;
        bindLifecycle as protected;
    }

    private int $sequence = 0;

    private function project(Project $project): Project
    {
        return $this->em()->getReference(Project::class, $project->requireId()) ?? throw new \LogicException('The project does not exist.');
    }

    protected function repository(Project $project, string $path): Uuid
    {
        $repository = new ForgeRepository($this->project($project), 'github', 'ext-'.++$this->sequence.uniqid(), $path, ForgeRepositorySource::Hook);
        $this->em()->persist($repository);
        $this->em()->flush();

        return $repository->id ?? throw new \LogicException('The repository is not flushed.');
    }

    /** @param array<string, mixed> $file */
    protected function read(Project $project, Uuid $repositoryId, array $file, bool $usable = true, ?string $blob = 'blob-1'): void
    {
        $this->writer()->apply($project->requireId(), $repositoryId, $blob, 'head-1', $file, $usable);
    }

    protected function store(Project $project, Uuid $repositoryId, string $key, string|bool $value): void
    {
        $this->em()->persist(new WorkflowRepositorySetting($this->project($project), $repositoryId, $key, SettingValues::encode($value)));
        $this->em()->flush();
    }

    /** @param array<string, bool|string> $values */
    protected function bindingValues(Project $project, array $values): void
    {
        $binding = $this->bindLifecycle($project);
        $binding->definition = [...$binding->definition, 'settingValues' => $values];
        $this->em()->flush();
        $this->resetServices();
    }

    protected function resetServices(): void
    {
        $source = self::getContainer()->get(TemplateSource::class);
        if ($source instanceof ProjectTemplateCopy) {
            $source->reset();
        }
        $resetter = self::getContainer()->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();
    }

    protected function writer(): RepositoryWorkflowSettingsWriter
    {
        $container = self::getContainer();

        return new WorkflowSettingsWriter(
            $container->get(ProjectTemplates::class),
            $container->get(WorkflowSettingOverrideRepository::class),
            $container->get(WorkflowRepositorySettingRepository::class),
            $this->em(),
            $container->get(\Symfony\Component\EventDispatcher\EventDispatcherInterface::class),
        );
    }

    protected function settings(): WorkflowSettings
    {
        $settings = self::getContainer()->get(WorkflowSettings::class);
        self::assertInstanceOf(WorkflowSettings::class, $settings);

        return $settings;
    }

    protected function rows(string $table, Uuid $repositoryId): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table.' WHERE repository_id = :id', ['id' => $repositoryId->toRfc4122()]);
    }

    protected function overrideRow(Uuid $repository, string $key): WorkflowSettingOverride
    {
        $this->em()->clear();
        $rows = self::getContainer()->get(WorkflowSettingOverrideRepository::class);
        self::assertInstanceOf(WorkflowSettingOverrideRepository::class, $rows);
        $row = $rows->findOneByRepositoryIdAndKey($repository, $key);
        self::assertInstanceOf(WorkflowSettingOverride::class, $row);

        return $row;
    }
}
