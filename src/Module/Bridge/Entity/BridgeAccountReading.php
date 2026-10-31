<?php

declare(strict_types=1);

namespace App\Module\Bridge\Entity;

use App\Module\Bridge\Repository\BridgeAccountReadingRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** One reading of a usage window of a bridge account, stored when it differs from the one before. */
#[ORM\Entity(repositoryClass: BridgeAccountReadingRepository::class)]
#[ORM\Index(name: 'idx_bridge_account_readings_latest', columns: ['owner_id', 'bridge_id', 'account', 'window_key', 'read_at'])]
#[ORM\Table(name: 'bridge_account_readings')]
class BridgeAccountReading
{
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

        #[ORM\Column(name: 'window_key', length: 16)]
        public readonly string $window,

        /** A percentage from 0 to 200. Null when the source gives no use. */
        #[ORM\Column(name: 'used_percent', nullable: true)]
        public readonly ?float $usedPercent,

        /** UTC. */
        #[ORM\Column(name: 'resets_at')]
        public readonly \DateTimeImmutable $resetsAt,

        #[ORM\Column]
        public readonly bool $limited,

        #[ORM\Column(length: 40)]
        public readonly string $source,

        /** UTC. When the bridge first reported these values. */
        #[ORM\Column(name: 'read_at')]
        public readonly \DateTimeImmutable $readAt,
    ) {
        $this->seenAt = $readAt;
    }

    /** UTC. When the bridge last reported these values. */
    #[ORM\Column(name: 'seen_at')]
    public \DateTimeImmutable $seenAt;
}
