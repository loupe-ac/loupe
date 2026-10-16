<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

use App\Module\Inbox\Repository\InboxBridgeOutageRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Ties a notice to the stretch of time in which no bridge could take the work of a project. */
#[ORM\Entity(repositoryClass: InboxBridgeOutageRepository::class)]
#[ORM\Table(name: 'inbox_bridge_outages')]
// One open outage per project. The predicate is written the way Postgres stores it.
#[ORM\UniqueConstraint(name: 'uniq_inbox_bridge_outages_open_project', columns: ['project_id'], options: ['where' => '(ended_at IS NULL)'])]
class InboxBridgeOutage
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $endedAt = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\OneToOne(targetEntity: InboxItem::class)]
        public readonly InboxItem $item,

        #[ORM\Column]
        public readonly \DateTimeImmutable $startedAt,
    ) {
        if (InboxItemKind::Notice !== $item->kind) {
            throw new \InvalidArgumentException('A bridge outage belongs to a notice.');
        }
    }
}
