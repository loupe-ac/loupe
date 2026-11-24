<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

use Symfony\Component\Form\FormView;

/** The open wait of a worker on a card, as the card page shows it. */
final readonly class CardWorkerWait
{
    public function __construct(
        public \DateTimeImmutable $openedAt,
        public \DateTimeImmutable $limitAt,
        /** The limit in minutes, counted from the start of the wait. */
        public int $limitMinutes,
        public FormView $limitForm,
    ) {
    }
}
