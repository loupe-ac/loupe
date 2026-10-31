<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Bridge\Repository\BridgeAccountReadingRepository;

final readonly class BridgeAccountReadingExporter implements UserDataExporterInterface
{
    public function __construct(
        private BridgeAccountReadingRepository $bridgeAccountReadings,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'bridge_account_readings.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        $ownerId = $user->id ?? throw new \LogicException('An exported user always has an id.');
        foreach ($this->bridgeAccountReadings->iterateByOwner($ownerId) as ['bridgeId' => $bridgeId, 'reading' => $reading]) {
            yield [
                'bridgeId' => $bridgeId,
                'account' => $reading->account,
                'window' => $reading->window,
                'usedPercent' => $reading->usedPercent,
                'resetsAt' => $reading->resetsAt->format(\DateTimeInterface::ATOM),
                'limited' => $reading->limited,
                'source' => $reading->source,
                'readAt' => $reading->readAt->format(\DateTimeInterface::ATOM),
                'seenAt' => $reading->seenAt->format(\DateTimeInterface::ATOM),
            ];
        }
    }
}
