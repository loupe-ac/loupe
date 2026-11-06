<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Account\Entity\User;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Project\Entity\Project;

/** What the take down page tells an admin about the project behind a code. */
final readonly class PublicLinkTakedownDetailView
{
    public function __construct(
        public PublicLink $link,
        public Project $project,
        public User $owner,
        public int $items,
        public bool $takenDown,
    ) {
    }
}
