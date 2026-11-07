<?php

declare(strict_types=1);

namespace App\Module\Workflow\Entity;

use App\Module\Project\Entity\Project;
use App\Module\Workflow\Repository\WorkflowRepositorySettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A value that the app stores for one repository, until `.loupe.yaml` states the key. Only a migration writes it.
 * The repository id has a foreign key in the migration alone, because this module cannot map a Forge entity.
 */
#[ORM\Entity(repositoryClass: WorkflowRepositorySettingRepository::class)]
#[ORM\Table(name: 'workflow_repository_settings')]
#[ORM\UniqueConstraint(name: 'uniq_workflow_repository_settings_repository_key', columns: ['repository_id', 'setting_key'])]
class WorkflowRepositorySetting
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

        /** The JSON of the value. */
        #[ORM\Column(type: Types::TEXT)]
        public string $value,
    ) {
    }
}
