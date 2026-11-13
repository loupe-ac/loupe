<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use OpenApi\Attributes as OA;

/** The answer of a resume or a cancel: the command it made or withdrew. */
#[OA\Schema(required: ['commandId'])]
final readonly class WorkerRunCommandResponse
{
    public function __construct(
        #[OA\Property(description: 'The id of the command that waits for the bridge, or the id of the command that was withdrawn.', format: 'uuid')]
        public string $commandId,
    ) {
    }
}
