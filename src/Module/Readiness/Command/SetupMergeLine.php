<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

final readonly class SetupMergeLine
{
    public function __construct(
        public string $repository,
        public SetupMergeStatus $status,
    ) {
    }
}
