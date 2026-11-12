<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use Symfony\Component\Uid\Uuid;

final readonly class ReadWorkflowFileCommand
{
    public function __construct(
        public Uuid $repositoryId,
        public bool $force = false,
        public int $attempt = 1,
    ) {
    }
}
