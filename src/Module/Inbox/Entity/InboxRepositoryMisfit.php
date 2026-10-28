<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

use App\Module\Inbox\Repository\InboxRepositoryMisfitRepository;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Ties a notice to the stretch of time in which a repository of a project blocks its new work. */
#[ORM\Entity(repositoryClass: InboxRepositoryMisfitRepository::class)]
#[ORM\Table(name: 'inbox_repository_misfits')]
// One open misfit per project. The predicate is written the way Postgres stores it.
#[ORM\UniqueConstraint(name: 'uniq_inbox_repository_misfits_open_project', columns: ['project_id'], options: ['where' => '(ended_at IS NULL)'])]
class InboxRepositoryMisfit
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $endedAt = null;

    /** @param list<array{id: string, path: string, gaps: int}> $repositories */
    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\OneToOne(targetEntity: InboxItem::class)]
        public readonly InboxItem $item,

        #[ORM\Column]
        public readonly \DateTimeImmutable $startedAt,

        /**
         * The blocking repositories that the notice names now, so the page can offer Check again for each.
         */
        #[ORM\Column(type: Types::JSON)]
        public array $repositories,
    ) {
        if (InboxItemKind::Notice !== $item->kind) {
            throw new \InvalidArgumentException('A repository misfit belongs to a notice.');
        }
    }
}
