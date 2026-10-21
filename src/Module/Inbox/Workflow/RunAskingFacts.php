<?php

declare(strict_types=1);

namespace App\Module\Inbox\Workflow;

/** Whether the newest run of the card waits on a question of its own. */
final readonly class RunAskingFacts
{
    /** The time after an answer that the bridge has to resume the worker, before the stop opens its own item. */
    public const string RESUME_GRACE = '+10 minutes';

    /**
     * @param bool                $askOpen     the question of the run is still open
     * @param ?\DateTimeImmutable $graceEndsAt when the grace after the answer ends, null with no answered question
     */
    public function __construct(
        public bool $askOpen,
        public ?\DateTimeImmutable $graceEndsAt,
    ) {
    }

    public function asking(\DateTimeImmutable $now): bool
    {
        return $this->askOpen || (null !== $this->graceEndsAt && $this->graceEndsAt > $now);
    }
}
