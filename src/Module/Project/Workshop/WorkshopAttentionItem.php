<?php

declare(strict_types=1);

namespace App\Module\Project\Workshop;

final readonly class WorkshopAttentionItem
{
    public function __construct(
        public string $title,
        public string $url,
        public string $kind,
        public bool $blocking,
        /** What the item is about: "document", "pull-request", "loupe" for a waiting card, or "agent" for a question or to-do. The row shows it as an icon. */
        public string $subject,
        /** The number of the first card the item links, so two items with the same title stay apart. */
        public ?int $cardNumber,
    ) {
    }
}
