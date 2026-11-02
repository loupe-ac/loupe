<?php

declare(strict_types=1);

namespace App\Module\Bridge\Entity;

use App\Module\Bridge\Repository\BridgeClaimRefusalRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A bridge whose claim on an open work request the server refused because of the account the claim named, or
 * because it named none. It lasts while the request waits: a good claim, a new report of the bridge or the close
 * of the request removes it.
 */
#[ORM\Entity(repositoryClass: BridgeClaimRefusalRepository::class)]
#[ORM\Table(name: 'bridge_claim_refusals')]
#[ORM\UniqueConstraint(name: 'uniq_bridge_claim_refusals_bridge_request', columns: ['bridge_id', 'work_request_id'])]
class BridgeClaimRefusal
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

        #[ORM\Column(length: 40, nullable: true)]
        public ?string $account,
    ) {
    }
}
