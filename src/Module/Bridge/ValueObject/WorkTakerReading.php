<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** The answer of the work takers check for one open work request. */
final readonly class WorkTakerReading
{
    /**
     * @param \DateTimeImmutable  $noTakerSince the latest of the start of the wait, the moment the last able bridge went quiet, and the last beat at which a bridge dropped what it takes
     * @param ?\DateTimeImmutable $quietAt      the moment the answer next changes because an online bridge turns quiet, or null when it cannot
     */
    public function __construct(
        public WorkTakerState $state,
        public \DateTimeImmutable $noTakerSince,
        public ?\DateTimeImmutable $quietAt,
        /** On a not-capable reading: an online bridge lacks the app-prompts capability that a request with a prompt needs. */
        public bool $appPromptsMissing = false,
        /** On an available or not-capable reading: the label of the newest online bridge that the state names. */
        public ?string $bridgeLabel = null,
        /** The last heartbeat of that bridge. */
        public ?\DateTimeImmutable $bridgeSeenAt = null,
        /**
         * On a none reading: the labels of the quiet bridges that could take the work and whose login expired.
         *
         * @var list<string>
         */
        public array $expiredTakers = [],
    ) {
    }
}
