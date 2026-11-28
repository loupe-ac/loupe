<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Bridge\Entity\WorkRequest;

/** What the adds of a command entry of one inbox item say about a further add. */
final readonly class EntryAdds
{
    public function __construct(
        /** An add is queued or claimed. */
        public bool $live,
        /** The request of the newest add, when the bridge refused it because its rule file holds an entry of the name. */
        public ?WorkRequest $refusedAsExisting,
    ) {
    }
}
