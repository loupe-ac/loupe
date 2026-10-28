<?php

declare(strict_types=1);

namespace App\Module\Bridge\Entity;

use App\Module\Bridge\Repository\ExperimentDefinitionRepository;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** The latest weights, metrics and headline the bridge reported for one experiment. */
#[ORM\Entity(repositoryClass: ExperimentDefinitionRepository::class)]
#[ORM\Table(name: 'bridge_experiment_definitions')]
#[ORM\UniqueConstraint(name: 'uniq_bridge_experiment_definition', columns: ['project_id', 'experiment'])]
class ExperimentDefinition
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /** @var list<string>|null the declared metric keys in their order, or null for the default metrics */
    #[ORM\Column(name: 'metrics', type: Types::JSON, nullable: true)]
    public ?array $metrics = null;

    /** @var array{goal?: array{metric: string, better?: string}, guards?: list<string>}|null the goal and guards of the experiment page, or null for the defaults */
    #[ORM\Column(name: 'headline', type: Types::JSON, nullable: true)]
    public ?array $headline = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public Project $project,

        #[ORM\Column(name: 'experiment', length: WorkerRun::MAX_EXPERIMENT_NAME_LENGTH)]
        public string $experiment,

        /** @var list<array{name: string, weight: int}> each variant and its weight, in the order of the rule */
        #[ORM\Column(name: 'weights', type: Types::JSON)]
        public array $weights,

        #[ORM\Column(name: 'reported_at')]
        public \DateTimeImmutable $reportedAt = new \DateTimeImmutable(),
    ) {
    }
}
