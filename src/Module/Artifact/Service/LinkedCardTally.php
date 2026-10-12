<?php

declare(strict_types=1);

namespace App\Module\Artifact\Service;

/** How many cards link an artifact, and the lowest number among them, as the library row shows "#611 +2". */
final readonly class LinkedCardTally
{
    public function __construct(
        public int $count,
        public int $firstNumber,
    ) {
    }
}
