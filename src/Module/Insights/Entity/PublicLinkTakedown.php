<?php

declare(strict_types=1);

namespace App\Module\Insights\Entity;

use App\Module\Account\Entity\User;
use App\Module\Insights\Repository\PublicLinkTakedownRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * An admin switched off every public link of a project. The row is the whole state. The switches of the
 * owner stay as they are, so deleting the row brings back the links that were on.
 */
#[ORM\Entity(repositoryClass: PublicLinkTakedownRepository::class)]
#[ORM\Table(name: 'insights_public_link_takedowns')]
class PublicLinkTakedown
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\OneToOne(targetEntity: Project::class)]
        public readonly Project $project,

        /** SET NULL, so deleting the admin account keeps the takedown. */
        #[ORM\JoinColumn(name: 'taken_down_by_id', nullable: true, onDelete: 'SET NULL')]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public readonly ?User $takenDownBy,

        #[ORM\Column(name: 'taken_down_at')]
        public readonly \DateTimeImmutable $takenDownAt = new \DateTimeImmutable(),
    ) {
    }
}
