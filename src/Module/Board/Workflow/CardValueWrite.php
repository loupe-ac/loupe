<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Workflow\Contract\Actor;

/** When a value was last written, and by which kind of writer. The time keeps its microseconds. */
final readonly class CardValueWrite
{
    public function __construct(
        public \DateTimeImmutable $at,
        public Actor $writerKind,
    ) {
    }
}
