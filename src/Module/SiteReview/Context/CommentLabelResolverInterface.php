<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Context;

use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

/**
 * Names what a saved comment belongs to, so the widget can say where an edit
 * went. The module that links a comment implements this, because SiteReview
 * does not know that link. Null means the comment names nothing to show.
 */
interface CommentLabelResolverInterface
{
    public function resolve(Uuid $commentId, Project $project): ?string;
}
