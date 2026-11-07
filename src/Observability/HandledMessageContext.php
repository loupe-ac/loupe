<?php

declare(strict_types=1);

namespace App\Observability;

use App\Messenger\Stamp\CauseStamp;

/** The cause stamp of a message that is being handled, and the transport it came from. */
final readonly class HandledMessageContext
{
    public function __construct(
        public ?CauseStamp $cause,
        public string $transport,
    ) {
    }
}
