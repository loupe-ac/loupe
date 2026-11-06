<?php

declare(strict_types=1);

namespace App\Module\Bridge\Entity;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Repository\ExperimentExclusionRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The owner left one card out of one experiment by hand. The report treats the
 * card like one with an automatic left-out reason.
 */
#[ORM\Entity(repositoryClass: ExperimentExclusionRepository::class)]
#[ORM\Table(name: 'bridge_experiment_exclusions')]
#[ORM\UniqueConstraint(name: 'uniq_bridge_experiment_exclusion', columns: ['project_id', 'experiment', 'card_id'])]
class ExperimentExclusion
{
    public const int MAX_REASON_LENGTH = 500;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        /** A scalar, never a foreign key, like the card of a pin. */
        #[ORM\Column(name: 'card_id', type: UuidType::NAME)]
        public readonly Uuid $cardId,

        #[ORM\Column(name: 'experiment', length: WorkerRun::MAX_EXPERIMENT_NAME_LENGTH)]
        public readonly string $experiment,

        #[ORM\Column(name: 'reason', length: self::MAX_REASON_LENGTH)]
        public string $reason,

        #[ORM\JoinColumn(name: 'excluded_by_id', nullable: true, onDelete: 'SET NULL')]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public ?User $excludedBy,

        #[ORM\Column(name: 'excluded_at')]
        public \DateTimeImmutable $excludedAt,
    ) {
    }
}
