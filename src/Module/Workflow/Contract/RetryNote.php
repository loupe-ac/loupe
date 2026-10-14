<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Names the inbox action of a retry and its item. It carries ids only, never the note. */
final readonly class RetryNote
{
    public function __construct(
        public Uuid $actionId,
        public Uuid $itemId,
        public int $itemNumber,
    ) {
    }
}
