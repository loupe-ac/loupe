<?php

declare(strict_types=1);

namespace App\Module\Board\View;

use App\Module\Account\Entity\User;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardValueType;

/**
 * One declared key of a card with the value it holds.
 *
 * The value, the writer and the time are null when the card holds no value for the
 * key, or holds one that does not match the declared type. The label, the caption,
 * the help and the history are translation keys.
 */
final readonly class CardValueView
{
    public function __construct(
        public string $key,
        public CardValueType $type,
        public string $label,
        public string $caption,
        public string $help,
        public string $history,
        public mixed $value = null,
        public ?Actor $writerKind = null,
        public ?User $writerUser = null,
        public ?\DateTimeImmutable $updatedAt = null,
    ) {
    }
}
