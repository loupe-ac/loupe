<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Context\CommentLabelResolverInterface;
use Symfony\Component\Uid\Uuid;

/** Names the card a comment is linked to, in the label the feedback save returns. */
final readonly class CardCommentLabelResolver implements CommentLabelResolverInterface
{
    public function __construct(
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
    ) {
    }

    #[\Override]
    public function resolve(Uuid $commentId, Project $project): ?string
    {
        $card = $this->cardSiteReviewComments->findOneByCommentId($commentId)?->card;
        if (null === $card || $card->project->id != $project->id) {
            return null;
        }

        return \sprintf('#%d %s', $card->number, $card->title);
    }
}
