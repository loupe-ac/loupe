<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Bridge\Repository\BridgeAccountTryNowRepository;

final readonly class BridgeAccountTryNowExporter implements UserDataExporterInterface
{
    public function __construct(
        private BridgeAccountTryNowRepository $bridgeAccountTryNows,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'bridge_account_try_now.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        $ownerId = $user->id ?? throw new \LogicException('An exported user always has an id.');
        foreach ($this->bridgeAccountTryNows->findByOwner($ownerId) as $override) {
            yield [
                'bridgeId' => (string) $override->bridge->id,
                'account' => $override->account,
                'pressedAt' => $override->pressedAt->format(\DateTimeInterface::ATOM),
                'closedAt' => $override->closedAt?->format(\DateTimeInterface::ATOM),
                'checkedAt' => $override->checkedAt?->format(\DateTimeInterface::ATOM),
                'stillLimited' => $override->stillLimited,
            ];
        }
    }
}
