<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

final readonly class StartWidgetCardResult
{
    public function __construct(
        /** False when the column was not offered at the write, or the workflow refused the move. */
        public bool $moved,
        /** The card and the moves it has after the write. */
        public WidgetCardStartView $view,
    ) {
    }
}
