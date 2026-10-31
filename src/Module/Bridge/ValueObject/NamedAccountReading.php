<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** The latest reading of one usage window of an account, with the name of the bridge that reported it. */
final readonly class NamedAccountReading
{
    public function __construct(
        public string $bridgeName,
        public string $account,
        public string $window,
        public ?float $usedPercent,
        public \DateTimeImmutable $resetsAt,
    ) {
    }
}
