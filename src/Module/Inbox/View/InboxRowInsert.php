<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

/** A changed row and the key of the next unchanged row it goes before, null for the end of the list. */
final readonly class InboxRowInsert
{
    public function __construct(
        public InboxRow $row,
        public ?string $before,
    ) {
    }
}
