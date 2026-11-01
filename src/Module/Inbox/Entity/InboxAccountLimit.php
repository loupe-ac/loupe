<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

use App\Module\Inbox\Repository\InboxAccountLimitRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Ties a notice to the stretch of time in which one account of one bridge held work of a project at its hard limit.
 * Two bridges can each name an account the same, and each such account has limits of its own.
 */
#[ORM\Entity(repositoryClass: InboxAccountLimitRepository::class)]
#[ORM\Table(name: 'inbox_account_limits')]
// One open episode for each project and bridge account. The predicate is written the way Postgres stores it.
#[ORM\UniqueConstraint(name: 'uniq_inbox_account_limits_open_account', columns: ['project_id', 'bridge_id', 'account'], options: ['where' => '(ended_at IS NULL)'])]
class InboxAccountLimit
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
        public readonly Uuid $bridgeId,

        #[ORM\Column(length: 40)]
        public readonly string $account,

        #[ORM\Column]
        public readonly \DateTimeImmutable $startedAt,
    ) {
        if (InboxItemKind::Notice !== $item->kind) {
            throw new \InvalidArgumentException('An account limit belongs to a notice.');
        }
    }

    /** The key of a bridge account in a map of episodes or limits. */
    public static function keyOf(Uuid $bridgeId, string $account): string
    {
        return $bridgeId->toRfc4122()."\0".$account;
    }
}
