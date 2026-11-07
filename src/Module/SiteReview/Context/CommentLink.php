<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Context;

/**
 * The card a listed comment belongs to, as the widget draws it. $locked is true
 * when the card started work, so the widget offers no edit and no delete.
 */
final readonly class CommentLink
{
    public function __construct(
        public string $label,
        public ?string $url,
        public string $cardId,
        public string $column,
        public bool $locked,
    ) {
    }
}
