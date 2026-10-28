<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\ValueObject\BridgeSessionReading;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/** One reading of an interactive session that the bridge launched. */
final class BridgeSessionReadingInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public ?string $sessionId = null,
        public ?bool $alive = null,
        public ?\DateTimeImmutable $transcriptAt = null,
    ) {
    }

    public function reading(): BridgeSessionReading
    {
        return new BridgeSessionReading(
            sessionId: Uuid::fromString($this->sessionId ?? throw new \LogicException('sessionId is required after validation.')),
            alive: $this->alive,
            transcriptAt: $this->transcriptAt?->setTimezone(new \DateTimeZone('UTC')),
        );
    }
}
