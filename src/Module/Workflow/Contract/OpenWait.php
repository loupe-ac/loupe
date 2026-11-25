<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** The open wait of a card, as the engine reads it. */
final readonly class OpenWait
{
    /** @param list<WaitedCondition> $conditions */
    public function __construct(
        public Uuid $id,
        public \DateTimeImmutable $openedAt,
        public \DateTimeImmutable $limitAt,
        public array $conditions,
    ) {
    }
}
