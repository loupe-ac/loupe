<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\PublicLink;

final readonly class ShowPublicLinkTakedownCommand
{
    public function __construct(
        public PublicLink $link,
    ) {
    }
}
