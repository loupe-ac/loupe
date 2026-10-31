<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Inbox\Entity\InboxCloser;

/** The message that ends a thread when an agent, Loupe or a rule closed the item. */
final readonly class InboxThreadClosing
{
    public function __construct(
        public ?InboxCloser $closer,
        public ?InboxOutcomeLabel $outcome,
        public ?\DateTimeImmutable $at,
        /** The reason of the closer, when it gave one. */
        public ?string $note,
    ) {
    }
}
