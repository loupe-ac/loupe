<?php

declare(strict_types=1);

namespace App\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/** Names the code that queued a message and the time of the event that made it queue. */
final readonly class CauseStamp implements StampInterface
{
    public function __construct(
        public string $cause,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
