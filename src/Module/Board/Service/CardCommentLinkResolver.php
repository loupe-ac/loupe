<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Context\CommentLink;
use App\Module\SiteReview\Context\CommentLinkResolverInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Links each listed comment to its card, as `#<number>` and the card page, with the column and the lock of the card. */
final readonly class CardCommentLinkResolver implements CommentLinkResolverInterface
{
    public function __construct(
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function resolve(array $comments, Project $project): array
    {
        $links = [];
        $byId = [];
        foreach ($comments as $comment) {
            $byId[(string) $comment->id] = $comment;
        }
        foreach ($this->cardSiteReviewComments->findForComments($comments) as $commentId => $link) {
            $card = $link->card;
            if ($card->project->id != $project->id) {
                continue;
            }
            $links[$commentId] = new CommentLink(
                \sprintf('#%d', $card->number),
                $this->urls->generate(
                    'app_board_card',
                    ['projectId' => (string) $project->id, 'cardId' => (string) $card->id],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
                (string) $card->id,
                $this->translator->trans($card->column->label),
                CardCommentEditLock::locks($byId[$commentId], $card, $card->leftBacklog),
            );
        }

        return $links;
    }
}
