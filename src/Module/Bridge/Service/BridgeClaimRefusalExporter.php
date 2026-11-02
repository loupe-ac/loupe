<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Bridge\Repository\BridgeClaimRefusalRepository;

final readonly class BridgeClaimRefusalExporter implements UserDataExporterInterface
{
    public function __construct(
        private BridgeClaimRefusalRepository $bridgeClaimRefusals,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'bridge_claim_refusals.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        $ownerId = $user->id ?? throw new \LogicException('An exported user always has an id.');

        yield from $this->bridgeClaimRefusals->iterateByOwner($ownerId);
    }
}
