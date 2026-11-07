<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\RepositorySettingsReadResult;
use App\Module\Workflow\Contract\RepositoryWorkflowSettingsApplied;
use App\Module\Workflow\Contract\RepositoryWorkflowSettingsWriter;
use App\Module\Workflow\Entity\WorkflowSettingOverride;
use App\Module\Workflow\Repository\WorkflowRepositorySettingRepository;
use App\Module\Workflow\Repository\WorkflowSettingOverrideRepository;
use App\Module\Workflow\Template\TemplateSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

#[AsAlias(RepositoryWorkflowSettingsWriter::class)]
final readonly class WorkflowSettingsWriter implements RepositoryWorkflowSettingsWriter
{
    public function __construct(
        private ProjectTemplates $templates,
        private WorkflowSettingOverrideRepository $workflowSettingOverrides,
        private WorkflowRepositorySettingRepository $workflowRepositorySettings,
        private EntityManagerInterface $em,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    #[\Override]
    public function isBlobUnchanged(Uuid $repositoryId, string $blobSha): bool
    {
        return $blobSha === $this->workflowSettingOverrides->findBlobShaByRepositoryId($repositoryId);
    }

    #[\Override]
    public function apply(Uuid $projectId, Uuid $repositoryId, ?string $blobSha, string $headSha, array $fileSettings, bool $fileUsable): RepositorySettingsReadResult
    {
        $rows = [];
        foreach ($this->workflowSettingOverrides->findByRepositoryId($repositoryId) as $row) {
            $rows[$row->settingKey] = $row;
        }
        $template = $this->templates->of($projectId);
        $warnings = [];
        $now = new \DateTimeImmutable();

        if ($fileUsable) {
            foreach ($fileSettings as $key => $raw) {
                $key = (string) $key;
                $setting = $template->setting($key);
                if (null === $setting) {
                    $warnings[] = SettingValues::unknownKey($key);

                    continue;
                }
                $valid = (\is_string($raw) || \is_bool($raw)) && TemplateSettings::accepts($setting, $raw);
                if (!$valid) {
                    $warnings[] = SettingValues::invalidValue($setting);
                }
                $value = $valid ? SettingValues::encode($raw) : null;

                $row = $rows[$key] ?? null;
                if (null === $row) {
                    $this->em->persist(new WorkflowSettingOverride($this->em->getReference(Project::class, $projectId) ?? throw new \LogicException('The project does not exist.'), $repositoryId, $key, $value, $valid, $blobSha, $headSha, $now));
                } else {
                    $row->value = $value;
                    $row->valid = $valid;
                    $row->blobSha = $blobSha;
                    $row->headSha = $headSha;
                    $row->readAt = $now;
                }
                unset($rows[$key]);

                $storedRow = $this->workflowRepositorySettings->findOneByRepositoryIdAndKey($repositoryId, $key);
                if (null !== $storedRow) {
                    $this->em->remove($storedRow);
                }
            }
        }

        foreach ($rows as $row) {
            $this->em->remove($row);
        }
        $this->em->flush();
        $this->dispatcher->dispatch(new RepositoryWorkflowSettingsApplied($projectId, $repositoryId));

        return new RepositorySettingsReadResult($warnings);
    }
}
