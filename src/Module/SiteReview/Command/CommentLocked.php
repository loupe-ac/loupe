<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use Symfony\Component\Uid\Uuid;

/**
 * Thrown when a widget edit or delete targets a comment whose card already
 * started work. The API controllers map this to a 409.
 */
final class CommentLocked extends \DomainException
{
    public static function forId(Uuid $id): self
    {
        return new self(\sprintf('The site-review comment "%s" belongs to a card that started work.', $id));
    }
}
