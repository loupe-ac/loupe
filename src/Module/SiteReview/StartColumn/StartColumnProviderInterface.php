<?php

declare(strict_types=1);

namespace App\Module\SiteReview\StartColumn;

use App\Module\Project\Entity\Project;

/**
 * Lists the columns a widget card may start in. The module that owns the
 * board implements it, because SiteReview does not know the columns. The
 * list is empty for a sign-in that may not start work.
 */
interface StartColumnProviderInterface
{
    /** @return list<StartColumn> the Backlog first, then each column the workflow allows from it */
    public function forCurrentRequest(Project $project): array;
}
