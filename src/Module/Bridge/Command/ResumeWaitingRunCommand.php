<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use Symfony\Component\Uid\Uuid;

final readonly class ResumeWaitingRunCommand
{
    public function __construct(
        public Uuid $waitId,
    ) {
    }
}
