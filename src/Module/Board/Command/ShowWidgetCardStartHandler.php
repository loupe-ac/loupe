<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Service\WidgetCardFinder;
use App\Module\Board\Service\WidgetStartOptions;

final readonly class ShowWidgetCardStartHandler
{
    public function __construct(
        private WidgetCardFinder $finder,
        private WidgetStartOptions $options,
    ) {
    }

    /** @throws WidgetCardNotFound */
    public function __invoke(ShowWidgetCardStartCommand $command): WidgetCardStartView
    {
        $card = $this->finder->find($command->project, $command->cardId);

        return new WidgetCardStartView($card, $this->options->allowedFor($card));
    }
}
