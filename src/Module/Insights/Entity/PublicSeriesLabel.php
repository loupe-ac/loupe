<?php

declare(strict_types=1);

namespace App\Module\Insights\Entity;

use App\Module\Insights\Repository\PublicSeriesLabelRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The number that a public page shows for one account or bridge of a project. The key of an account is its name.
 * The key of a bridge is its id, so a renamed bridge keeps its number.
 */
#[ORM\Entity(repositoryClass: PublicSeriesLabelRepository::class)]
#[ORM\Table(name: 'insights_public_series_labels')]
#[ORM\UniqueConstraint(name: 'uniq_insights_public_series_label', columns: ['project_id', 'kind', 'key'])]
class PublicSeriesLabel
{
    public const int MAX_KEY_LENGTH = 255;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column(name: 'kind', length: 16, enumType: PublicSeriesLabelKind::class)]
        public readonly PublicSeriesLabelKind $kind,

        #[ORM\Column(name: 'key', length: self::MAX_KEY_LENGTH)]
        public readonly string $key,

        #[ORM\Column(name: 'number')]
        public readonly int $number,
    ) {
    }
}
