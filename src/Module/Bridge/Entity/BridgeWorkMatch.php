<?php

declare(strict_types=1);

namespace App\Module\Bridge\Entity;

use App\Module\Bridge\Repository\BridgeWorkMatchRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The account a bridge matched to an open work request. It lasts while the
 * request waits: the claim or the close of the request removes it.
 */
#[ORM\Entity(repositoryClass: BridgeWorkMatchRepository::class)]
#[ORM\Table(name: 'bridge_work_matches')]
#[ORM\UniqueConstraint(name: 'uniq_bridge_work_matches_bridge_request', columns: ['bridge_id', 'work_request_id'])]
class BridgeWorkMatch
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

        #[ORM\JoinColumn(name: 'work_request_id', nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: WorkRequest::class)]
        public readonly WorkRequest $workRequest,

        #[ORM\Column(length: 40)]
        public string $account,
    ) {
    }
}
