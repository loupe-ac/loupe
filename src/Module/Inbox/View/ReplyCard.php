<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Inbox\Entity\InboxReview;

/** The card where the owner answers one open item: its fields, a hint, the main action, the menu and Dismiss. */
final readonly class ReplyCard
{
    /**
     * @param list<ReplyField>   $fields     the fields the item takes, in the order the card shows them
     * @param ?string            $hintKey    the translation key of the hint line
     * @param list<ThreadAction> $menu       the actions under the chevron, without the main one and without Dismiss
     * @param ?InboxReview       $review     the review behind a review item, which names its target
     * @param bool               $targetGone a review whose document or pull request is gone, so no verdict is possible
     */
    public function __construct(
        public array $fields,
        public ?string $hintKey,
        public ?ThreadAction $main,
        public array $menu,
        public ?ThreadAction $dismiss,
        public ?InboxReview $review = null,
        public bool $targetGone = false,
    ) {
    }

    public function takes(ReplyField $field): bool
    {
        return \in_array($field, $this->fields, true);
    }
}
