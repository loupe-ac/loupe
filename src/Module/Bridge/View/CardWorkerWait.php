<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

use Symfony\Component\Form\FormView;

/**
 * The open wait of a worker on a card, or its stranded wait, as the card page shows it. A stranded wait ended while its bridge
 * was offline, so it has no time limit to change and no form for one.
 */
final readonly class CardWorkerWait
{
    public function __construct(
        public \DateTimeImmutable $openedAt,
        public \DateTimeImmutable $limitAt,
        /** The limit in minutes, counted from the start of the wait. */
        public int $limitMinutes,
        public ?FormView $limitForm = null,
        public bool $stranded = false,
    ) {
    }
}
