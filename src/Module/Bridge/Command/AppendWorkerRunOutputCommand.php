<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use Symfony\Component\Uid\Uuid;

/** Text for the live output log of the run the bridge gave this key, at a byte offset. */
final readonly class AppendWorkerRunOutputCommand
{
    public function __construct(
        public User $owner,
        public string $handle,
        public Uuid $runKey,
        public Uuid $bridgeId,
        public int $offset,
        public string $text,
    ) {
    }
}
