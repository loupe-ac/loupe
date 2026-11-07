<?php

declare(strict_types=1);

namespace App\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/** The time a message was dispatched, and the time it falls due once a delay has passed. */
final readonly class DispatchedAtStamp implements StampInterface
{
    public function __construct(
        public \DateTimeImmutable $dispatchedAt,
        public \DateTimeImmutable $dueAt,
    ) {
    }
}
