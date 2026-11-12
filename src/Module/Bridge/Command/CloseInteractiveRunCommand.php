<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Project\Entity\Project;
use App\Security\ApiKeyActor;
use Symfony\Component\Uid\Uuid;

final readonly class CloseInteractiveRunCommand
{
    public function __construct(
        public Project $project,
        public Uuid $runId,
        /** The API key that stops the run. Null for a person on a page or an agent. */
        public ?ApiKeyActor $apiKey = null,
    ) {
    }
}
