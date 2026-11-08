<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardSourceKind;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\SiteReview\Context\CommentEditLockInterface;
use App\Module\SiteReview\Entity\SiteReviewComment;

/**
 * A note locks once its widget card ever left the Backlog, also after the card
 * came back. A note saved on the preview page of the card itself stays open.
 */
final readonly class CardCommentEditLock implements CommentEditLockInterface
{
    public function __construct(
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private CardRepository $cards,
    ) {
    }

    /** Reads the flag from the row, so call it inside the transaction that also writes. */
    #[\Override]
    public function isLocked(SiteReviewComment $comment): bool
    {
        $link = $this->cardSiteReviewComments->findOneBy(['comment' => $comment]);
        if (null === $link || CardSourceKind::Widget !== $link->card->source->kind) {
            return false;
        }

        return self::locks($comment, $link->card, $this->cards->hasLeftBacklog($link->card));
    }

    public static function locks(SiteReviewComment $comment, Card $card, bool $started): bool
    {
        return $started
            && CardSourceKind::Widget === $card->source->kind
            && 'card:'.$card->id !== $comment->context;
    }
}
