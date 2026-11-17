<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

use Symfony\Component\Uid\Uuid;

/** Names one launch of a worker: the bridge that ran it and the run key that bridge gave it. */
final readonly class WorkerRunKey
{
    public function __construct(
        public Uuid $bridgeId,
        public Uuid $runKey,
    ) {
    }
}
