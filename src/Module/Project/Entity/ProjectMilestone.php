<?php

declare(strict_types=1);

namespace App\Module\Project\Entity;

use App\Module\Project\Repository\ProjectMilestoneRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** A fact about a project that came true once and stays true. The key is free text. */
#[ORM\Entity(repositoryClass: ProjectMilestoneRepository::class)]
#[ORM\Table(name: 'project_milestones')]
#[ORM\UniqueConstraint(name: 'uniq_project_milestone_key', columns: ['project_id', 'key'])]
class ProjectMilestone
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

        #[ORM\Column(name: '`key`', length: 100)]
        public readonly string $key,

        #[ORM\Column]
        public readonly \DateTimeImmutable $reachedAt = new \DateTimeImmutable(),
    ) {
    }
}
