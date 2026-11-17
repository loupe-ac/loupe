<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\BoardPlacementContextReader;
use App\Module\Board\Service\CardPlacer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/** Places many cards against one read of the board-wide values. */
final readonly class ShowCardPlacementsHandler
{
    public function __construct(
        private BoardPlacementContextReader $contextReader,
        private CardPlacer $placer,
        private CardRepository $cards,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ShowCardPlacementsCommand $command): ShowCardPlacementsView
    {
        $found = [];
        foreach ($this->cards->findByIdsInProject($command->project, array_map(Uuid::fromString(...), $command->cardIds)) as $card) {
            $found[(string) $card->id] = $card;
        }

        $context = $this->contextReader->read($command->project, array_values(array_map(static fn (Card $card): Uuid => $card->id ?? throw new \LogicException('A placed card has an id.'), $found)));
        $placements = [];
        foreach ($command->cardIds as $cardId) {
            // One card that cannot be placed leaves the others their update, and the page retries it.
            try {
                $placements[$cardId] = $this->placer->place($context, $found[$cardId] ?? null);
            } catch (\Throwable $exception) {
                $this->logger->error('board.placement_failed', ['cardId' => $cardId, 'projectId' => (string) $command->project->id, 'exception' => $exception]);
            }
        }

        return new ShowCardPlacementsView($placements);
    }
}
