<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Context;

use App\Module\SiteReview\Entity\SiteReviewComment;

/**
 * Tells whether a comment may no longer be edited or deleted. The module that
 * knows the cards implements it, because SiteReview does not know them. A
 * caller asks inside the transaction that also writes.
 */
interface CommentEditLockInterface
{
    public function isLocked(SiteReviewComment $comment): bool;
}
