<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Context;

use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewComment;

/**
 * Names the page each listed comment lives on, so a widget row can link to
 * it. The module that links a comment implements this, because SiteReview
 * does not know that link. A comment with nothing to link is absent.
 */
interface CommentLinkResolverInterface
{
    /**
     * @param list<SiteReviewComment> $comments
     *
     * @return array<string, ContextLabel> keyed by comment id
     */
    public function resolve(array $comments, Project $project): array;
}
