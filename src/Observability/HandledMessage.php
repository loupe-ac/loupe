<?php

declare(strict_types=1);

namespace App\Observability;

use Symfony\Contracts\Service\ResetInterface;

/** The message that a worker, or the sync transport, handles now. It lets the code under a handler name the cause of the message. */
final class HandledMessage implements ResetInterface
{
    public ?HandledMessageContext $current = null;

    #[\Override]
    public function reset(): void
    {
        $this->current = null;
    }
}
