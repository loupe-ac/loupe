<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Context\CommentScopeResolverInterface;
use App\Module\Workflow\Contract\CardTypeCatalog;
use Symfony\Component\Uid\Uuid;

/**
 * Answers which comments a `card:<uuid>`, `epic:<uuid>` or
 * `cards:<uuid>,<uuid>` scope covers: the comments linked to that card, to the
 * children of that epic, or to any of those cards.
 *
 * The link table is the source, because a comment made in an open review
 * carries no context of its own. A card of another project, a closed card, a
 * card that is gone and a malformed id are dropped, since the scope arrives
 * from a page anyone can edit.
 */
final readonly class CardCommentScopeResolver implements CommentScopeResolverInterface
{
    private const string CARD = 'card:';
    private const string EPIC = 'epic:';
    private const string CARDS = 'cards:';

    /** The widget sends 50 ids at most, and the server reads no more. */
    private const int MAX_CARDS = 50;

    public function __construct(
        private CardRepository $cards,
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private CardTypeCatalog $catalog,
    ) {
    }

    #[\Override]
    public function resolve(string $scope, Project $project): ?array
    {
        if (str_starts_with($scope, self::CARDS)) {
            return $this->cardSiteReviewComments->findCommentIdsForCards(self::open($this->cardsOf($project, substr($scope, \strlen(self::CARDS)))));
        }

        $epic = str_starts_with($scope, self::EPIC);
        if (!$epic && !str_starts_with($scope, self::CARD)) {
            return null;
        }

        $id = substr($scope, \strlen($epic ? self::EPIC : self::CARD));
        if (!Uuid::isValid($id)) {
            return [];
        }

        $card = $this->cards->find(Uuid::fromString($id));
        if (null === $card || $card->project->id != $project->id || $card->column->terminal) {
            return [];
        }

        if (!$epic) {
            return $this->cardSiteReviewComments->findCommentIdsForCards([$card]);
        }

        if (!$this->catalog->forProject($project->requireId())->get($card->type)->children) {
            return [];
        }

        return $this->cardSiteReviewComments->findCommentIdsForCards(self::open($this->cards->findChildren($card)));
    }

    /**
     * @param list<Card> $cards
     *
     * @return list<Card>
     */
    private static function open(array $cards): array
    {
        return array_values(array_filter($cards, static fn (Card $card): bool => !$card->column->terminal));
    }

    /** @return list<Card> */
    private function cardsOf(Project $project, string $list): array
    {
        $ids = [];
        foreach (\array_slice(explode(',', $list), 0, self::MAX_CARDS) as $id) {
            $id = trim($id);
            if (Uuid::isValid($id)) {
                $ids[] = Uuid::fromString($id);
            }
        }

        return $this->cards->findByIdsInProject($project, $ids);
    }
}
