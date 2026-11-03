<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Api\PageLinks;

final readonly class ShowProjectLinkHandler
{
    public function __construct(
        private PageLinks $links,
    ) {
    }

    /** The absolute link to the project home page in the app. */
    public function __invoke(ShowProjectLinkCommand $command): string
    {
        return $this->links->project((string) $command->project->id);
    }
}
