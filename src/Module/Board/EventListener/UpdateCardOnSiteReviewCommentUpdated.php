<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Board\Service\FeedbackCardTitle;
use App\Module\SiteReview\Event\SiteReviewCommentUpdated;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardTypeCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A card that a note created follows the note's edit, until someone else works on the card. */
#[AsEventListener]
final readonly class UpdateCardOnSiteReviewCommentUpdated
{
    public function __construct(
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private CardRepository $cards,
        private CardEventRepository $cardEvents,
        private CardTypeCatalog $catalog,
        private UpdateCardHandler $updateCard,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(SiteReviewCommentUpdated $event): void
    {
        $link = $this->cardSiteReviewComments->findOneByCommentId($event->commentId);
        if (null === $link || !$link->createdCard) {
            return;
        }

        $card = $link->card;
        $this->cards->refreshColumn($card);
        $this->cards->refreshTypeAndParent($card);
        $createdType = $this->cardEvents->createdType($card) ?? $this->catalog->forProject($event->projectId)->defaultKey;
        if ($this->cards->hasWork($card, $link->createdTitle ?? $card->title, $link->createdBody ?? '', $createdType)) {
            return;
        }

        $title = FeedbackCardTitle::of($event->body);
        $body = Card::normalText($event->body);
        if ($title === $card->title && $body === $card->body) {
            return;
        }

        ($this->updateCard)(new UpdateCardCommand($card, Actor::Reviewer, title: $title, body: $body));
        $link->createdTitle = $card->title;
        $link->createdBody = $card->body;
        $this->em->flush();
    }
}
