<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

use Symfony\Component\Uid\Uuid;

/** What a bridge saw of one interactive session that it launched. */
final readonly class BridgeSessionReading
{
    /**
     * @param bool|null               $alive        null when the harness cannot tell whether the process runs
     * @param \DateTimeImmutable|null $transcriptAt when the transcript last changed, null when the bridge found none
     */
    public function __construct(
        public Uuid $sessionId,
        public ?bool $alive,
        public ?\DateTimeImmutable $transcriptAt,
    ) {
    }
}
