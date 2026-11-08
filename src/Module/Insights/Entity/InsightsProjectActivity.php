<?php

declare(strict_types=1);

namespace App\Module\Insights\Entity;

use App\Module\Insights\Repository\InsightsProjectActivityRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\Mapping as ORM;

/**
 * When a project last had a view of its Dashboard or a share link, and when it last got new data. The repository
 * writes the table with the connection, so this class serves the schema.
 */
#[ORM\Entity(repositoryClass: InsightsProjectActivityRepository::class)]
#[ORM\Table(name: 'insights_project_activity')]
class InsightsProjectActivity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\JoinColumn(name: 'project_id', nullable: false, onDelete: 'CASCADE')]
        #[ORM\OneToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column(name: 'viewed_at', nullable: true)]
        public ?\DateTimeImmutable $viewedAt = null,

        #[ORM\Column(name: 'data_at', nullable: true)]
        public ?\DateTimeImmutable $dataAt = null,
    ) {
    }
}
