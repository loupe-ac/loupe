<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

use Symfony\Component\Uid\Uuid;

/** One stored reading that has a use value, as the account usage metric reads it. */
final readonly class AccountUsageReading
{
    public function __construct(
        public Uuid $id,
        public Uuid $bridgeId,
        public string $account,
        public string $window,
        public float $usedPercent,
        public \DateTimeImmutable $readAt,
    ) {
    }
}
