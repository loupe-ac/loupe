<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Context\CommentLinkResolverInterface;
use App\Module\SiteReview\Context\ContextLabel;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Links each listed comment to its card, as `#<number>` and the card page. */
final readonly class CardCommentLinkResolver implements CommentLinkResolverInterface
{
    public function __construct(
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[\Override]
    public function resolve(array $comments, Project $project): array
    {
        $links = [];
        foreach ($this->cardSiteReviewComments->findForComments($comments) as $commentId => $link) {
            $card = $link->card;
            if ($card->project->id != $project->id) {
                continue;
            }
            $links[$commentId] = new ContextLabel(
                \sprintf('#%d', $card->number),
                $this->urls->generate(
                    'app_board_card',
                    ['projectId' => (string) $project->id, 'cardId' => (string) $card->id],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
            );
        }

        return $links;
    }
}
