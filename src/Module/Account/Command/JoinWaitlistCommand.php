<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Entity\WaitlistJoinSource;
use App\Module\Account\Entity\WaitlistPick;

final readonly class JoinWaitlistCommand
{
    /** @param list<WaitlistPick> $picks */
    public function __construct(
        /** @phpstan-var non-empty-string */
        public string $email,
        public array $picks = [],
        public ?string $other = null,
        public WaitlistJoinSource $source = WaitlistJoinSource::Form,
    ) {
    }
}
