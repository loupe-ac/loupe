<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

use App\Module\Inbox\Repository\InboxOpenRouterKeyNoticeRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Ties a notice to the stretch of time in which OpenRouter refused one key in one project.
 * The key id is a plain column, because the key may be deleted while the notice lives on.
 */
#[ORM\Entity(repositoryClass: InboxOpenRouterKeyNoticeRepository::class)]
#[ORM\Table(name: 'inbox_openrouter_key_notices')]
// One open notice per key and project. The predicate is written the way Postgres stores it.
#[ORM\UniqueConstraint(name: 'uniq_inbox_openrouter_key_notices_open', columns: ['key_id', 'project_id'], options: ['where' => '(ended_at IS NULL)'])]
class InboxOpenRouterKeyNotice
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

        #[ORM\Column(type: UuidType::NAME)]
        public readonly Uuid $keyId,

        #[ORM\Column]
        public readonly \DateTimeImmutable $startedAt,
    ) {
        if (InboxItemKind::Notice !== $item->kind) {
            throw new \InvalidArgumentException('An OpenRouter key notice belongs to a notice.');
        }
    }
}
