<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use App\Search\SearchProviderInterface;
use App\Search\SearchResult;
use App\Search\SearchResults;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class CardSearchProvider implements SearchProviderInterface
{
    public function __construct(
        private CardRepository $cards,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function search(Project $project, string $query, int $page): SearchResults
    {
        if ('' === $query) {
            return new SearchResults();
        }
        $cards = $this->cards->searchByProject($project, $query, $page, self::PAGE_SIZE);
        $items = [];
        foreach ($cards as $card) {
            $items[] = new SearchResult(
                $card->title,
                $this->urls->generate('app_board_card', ['projectId' => (string) $project->id, 'cardId' => (string) $card->id]),
                'card',
                $card->number,
                $this->translator->trans($card->column->label),
            );
        }

        return new SearchResults($items, $page * self::PAGE_SIZE < count($cards));
    }
}
