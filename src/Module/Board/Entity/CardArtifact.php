<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

use App\Module\Artifact\Entity\Artifact;
use App\Module\Board\Repository\CardArtifactRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One artifact linked to one card.
 *
 * The row lives in Board, so Artifact never learns that cards exist. Both sides
 * cascade on delete, so neither module has to know the link is there when it
 * removes its own row.
 */
#[ORM\Entity(repositoryClass: CardArtifactRepository::class)]
#[ORM\Table(name: 'board_card_artifacts')]
#[ORM\UniqueConstraint(name: 'uniq_board_card_artifact', columns: ['card_id', 'artifact_id'])]
class CardArtifact
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Card::class, inversedBy: 'artifacts')]
        public readonly Card $card,

        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Artifact::class)]
        public readonly Artifact $artifact,

        #[ORM\Column]
        public readonly \DateTimeImmutable $linkedAt = new \DateTimeImmutable(),
    ) {
    }
}
