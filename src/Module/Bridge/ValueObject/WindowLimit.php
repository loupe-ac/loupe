<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

final readonly class WindowLimit
{
    public function __construct(
        public AccountReadingReport $reading,
        public WindowLimitState $state,
    ) {
    }
}
