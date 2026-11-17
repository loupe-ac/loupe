<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Service\BoardPlacementContextReader;
use App\Module\Board\Service\CardPlacer;

/** Reads one card and its neighbours, so the cost does not grow with the board. */
final readonly class ShowCardPlacementHandler
{
    public function __construct(
        private BoardPlacementContextReader $contextReader,
        private CardPlacer $placer,
    ) {
    }

    public function __invoke(ShowCardPlacementCommand $command): CardPlacementView
    {
        return $this->placer->place($this->contextReader->read($command->project, null === $command->card ? [] : [$command->card->id ?? throw new \LogicException('A placed card has an id.')]), $command->card);
    }
}
