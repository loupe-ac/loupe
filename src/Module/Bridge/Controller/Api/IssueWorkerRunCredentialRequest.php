<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use OpenApi\Attributes as OA;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/** The launch that asks for a run credential. */
#[OA\Schema]
final class IssueWorkerRunCredentialRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        #[OA\Property(description: 'The id of the bridge that launches the worker.', format: 'uuid')]
        public ?string $bridgeId = null,

        #[Assert\NotBlank]
        #[Assert\Uuid]
        #[OA\Property(description: 'The id of the session the worker runs in.', format: 'uuid')]
        public ?string $sessionId = null,
    ) {
    }

    public function bridgeId(): Uuid
    {
        return Uuid::fromString($this->bridgeId ?? throw new \LogicException('bridgeId is required after validation.'));
    }

    public function sessionId(): Uuid
    {
        return Uuid::fromString($this->sessionId ?? throw new \LogicException('sessionId is required after validation.'));
    }
}
