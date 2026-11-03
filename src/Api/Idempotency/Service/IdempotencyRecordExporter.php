<?php

declare(strict_types=1);

namespace App\Api\Idempotency\Service;

use App\Api\Idempotency\Repository\IdempotencyRecordRepository;
use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;

final readonly class IdempotencyRecordExporter implements UserDataExporterInterface
{
    public function __construct(
        private IdempotencyRecordRepository $idempotencyRecords,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'idempotency_records.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        $ownerId = $user->id ?? throw new \LogicException('a persisted user always has an id');

        foreach ($this->idempotencyRecords->iterateByOwner($ownerId) as $row) {
            yield [
                'id' => $row['id'],
                'credential' => $row['credential'],
                'idempotencyKey' => $row['idempotencyKey'],
                'requestHash' => $row['requestHash'],
                'state' => $row['state']->value,
                'status' => $row['status'],
                'headers' => $row['headers'],
                'body' => $row['body'],
                'createdAt' => $row['createdAt']->format(\DateTimeInterface::ATOM),
                'expiresAt' => $row['expiresAt']->format(\DateTimeInterface::ATOM),
            ];
        }
    }
}
