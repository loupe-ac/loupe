<?php

declare(strict_types=1);

namespace App\Module\Bridge\Entity;

use App\Module\Bridge\Repository\BridgeAccountTryNowRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A person pressed Try now for a bridge account. While the override is open, the
 * account takes work even at or above its stop value. It holds no reading, so
 * the bars and the usage metric keep the measured values.
 */
#[ORM\Entity(repositoryClass: BridgeAccountTryNowRepository::class)]
#[ORM\Index(name: 'idx_bridge_account_try_now_bridge', columns: ['owner_id', 'bridge_id', 'account', 'pressed_at'])]
#[ORM\Table(name: 'bridge_account_try_now')]
// One open override for each account of a bridge. The predicate is written the way Postgres stores it.
#[ORM\UniqueConstraint(name: 'uniq_bridge_account_try_now_open', columns: ['owner_id', 'bridge_id', 'account'], options: ['where' => '(closed_at IS NULL)'])]
class BridgeAccountTryNow
{
    /** The minutes an override lasts when no reading of the account arrives. */
    public const int LIFETIME_MINUTES = 15;

    /** The first reading of the account that the bridge read after the press. */
    #[ORM\Column(name: 'checked_at', nullable: true)]
    public ?\DateTimeImmutable $checkedAt = null;

    /** Whether that reading still shows the hard limit. Null until a reading closes the override. */
    #[ORM\Column(name: 'still_limited', nullable: true)]
    public ?bool $stillLimited = null;

    #[ORM\Column(name: 'closed_at', nullable: true)]
    public ?\DateTimeImmutable $closedAt = null;

    public function __construct(
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        #[ORM\Id]
        public readonly Uuid $id,

        // Account deletion removes bridges with SQL, so only the database can remove the row.
        #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'owner_id', nullable: false, onDelete: 'CASCADE')]
        #[ORM\JoinColumn(name: 'bridge_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Bridge::class)]
        public readonly Bridge $bridge,

        #[ORM\Column(length: 40)]
        public readonly string $account,

        /** UTC. */
        #[ORM\Column(name: 'pressed_at')]
        public readonly \DateTimeImmutable $pressedAt,
    ) {
    }

    /** The override holds while it is not closed and its minutes have not run out. */
    public function isOpenAt(\DateTimeImmutable $now): bool
    {
        return null === $this->closedAt && $this->expiresAt() > $now;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->pressedAt->modify(\sprintf('+%d minutes', self::LIFETIME_MINUTES));
    }
}
