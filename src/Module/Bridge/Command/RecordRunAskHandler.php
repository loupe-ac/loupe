<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

/**
 * Records that a worker run waits on an inbox ask. The caller holds the
 * transaction and flushes it, because the ask commits with the link.
 */
final readonly class RecordRunAskHandler
{
    public function __invoke(RecordRunAskCommand $command): void
    {
        $command->run->waitsOnAskId = $command->askId;
    }
}
