<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** One repository of a project, as the workflow settings need to name it. */
final readonly class RepositoryRef
{
    public function __construct(
        public Uuid $id,
        public string $forge,
        public string $externalId,
        public string $path,
    ) {
    }
}
