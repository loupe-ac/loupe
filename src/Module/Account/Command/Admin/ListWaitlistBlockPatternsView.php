<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Admin;

use App\Module\Account\Entity\WaitlistBlockPattern;

final readonly class ListWaitlistBlockPatternsView
{
    /**
     * @param list<WaitlistBlockPattern> $blockPatterns
     */
    public function __construct(
        public array $blockPatterns,
        public int $hiddenCount,
    ) {
    }
}
