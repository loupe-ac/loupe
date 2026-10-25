<?php

declare(strict_types=1);

namespace App\Module\Notification\Command;

use App\Module\Notification\Contract\OpenPushTags;

final readonly class ListOpenPushTagsHandler
{
    public function __construct(
        private OpenPushTags $openTags,
    ) {
    }

    public function __invoke(ListOpenPushTagsCommand $command): ListOpenPushTagsView
    {
        return new ListOpenPushTagsView($this->openTags->forUser($command->user));
    }
}
