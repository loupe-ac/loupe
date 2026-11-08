<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Service\WidgetCardFinder;
use App\Module\Board\Service\WidgetStartOptions;
use App\Module\Workflow\Contract\Actor;

/** Moves a widget card to a column the guard offers the reviewer, through the update every board move uses. */
final readonly class StartWidgetCardHandler
{
    public function __construct(
        private WidgetCardFinder $finder,
        private WidgetStartOptions $options,
        private UpdateCardHandler $update,
    ) {
    }

    /** @throws WidgetCardNotFound */
    public function __invoke(StartWidgetCardCommand $command): StartWidgetCardResult
    {
        $card = $this->finder->find($command->project, $command->cardId);
        $moves = $this->options->allowedFor($card);
        $target = array_find($moves, static fn (BoardColumn $column): bool => (string) $column->id === strtolower($command->columnId));
        if (null === $target) {
            return new StartWidgetCardResult(false, new WidgetCardStartView($card, $moves));
        }

        try {
            ($this->update)(new UpdateCardCommand($card, Actor::Reviewer, column: $target, expectedColumn: $card->column));
        } catch (CardManaged|DomainErrors|EpicChildrenOpen) {
            return new StartWidgetCardResult(false, new WidgetCardStartView($card, $this->options->allowedFor($card)));
        }

        return new StartWidgetCardResult(true, new WidgetCardStartView($card, $this->options->allowedFor($card)));
    }
}
