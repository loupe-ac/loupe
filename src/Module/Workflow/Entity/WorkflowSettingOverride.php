<?php

declare(strict_types=1);

namespace App\Module\Workflow\Entity;

use App\Module\Project\Entity\Project;
use App\Module\Workflow\Repository\WorkflowSettingOverrideRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * What `.loupe.yaml` on the default branch of one repository says for one setting.
 * The repository id has a foreign key in the migration alone, because this module cannot map a Forge entity.
 */
#[ORM\Entity(repositoryClass: WorkflowSettingOverrideRepository::class)]
#[ORM\Table(name: 'workflow_setting_overrides')]
#[ORM\UniqueConstraint(name: 'uniq_workflow_setting_overrides_repository_key', columns: ['repository_id', 'setting_key'])]
class WorkflowSettingOverride
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column(type: UuidType::NAME)]
        public readonly Uuid $repositoryId,

        #[ORM\Column(length: 64)]
        public readonly string $settingKey,

        /** The JSON of the value, or null when the file value is not allowed. */
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        public ?string $value,

        #[ORM\Column]
        public bool $valid,

        #[ORM\Column(length: 64, nullable: true)]
        public ?string $blobSha,

        #[ORM\Column(length: 64)]
        public string $headSha,

        #[ORM\Column]
        public \DateTimeImmutable $readAt = new \DateTimeImmutable(),
    ) {
    }
}
