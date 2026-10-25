<?php

declare(strict_types=1);

namespace App\Module\Notification\Command;

final readonly class ListOpenPushTagsView
{
    /** @param list<string> $tags the tags of the pushes that still stand */
    public function __construct(
        public array $tags,
    ) {
    }
}
