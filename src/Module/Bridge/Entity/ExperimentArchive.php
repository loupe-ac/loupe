<?php

declare(strict_types=1);

namespace App\Module\Bridge\Entity;

use App\Module\Bridge\Repository\ExperimentArchiveRepository;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** The owner's mark that one experiment is finished, with the runs the mark already saw. */
#[ORM\Entity(repositoryClass: ExperimentArchiveRepository::class)]
#[ORM\Table(name: 'bridge_experiment_archives')]
#[ORM\UniqueConstraint(name: 'uniq_bridge_experiment_archive', columns: ['project_id', 'experiment'])]
class ExperimentArchive
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /**
     * @param list<string> $coveredRunIds every run of the project received in the archive second or later, whatever experiment it names
     */
    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public Project $project,

        #[ORM\Column(name: 'experiment', length: WorkerRun::MAX_EXPERIMENT_NAME_LENGTH)]
        public string $experiment,

        #[ORM\Column(name: 'archived_at')]
        public \DateTimeImmutable $archivedAt,

        #[ORM\Column(name: 'covered_run_ids', type: Types::JSON)]
        public array $coveredRunIds,
    ) {
    }
}
