<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** A dated event of a card that a duration metric starts or ends at. A slot event names the slot. */
final readonly class DurationEvent
{
    public const string PR_OPENED = 'pr-opened';
    public const string AGENT_REVIEW_PASSED = 'agent-review-passed';
    public const string READY_TO_MERGE = 'ready-to-merge';
    public const string PR_MERGED = 'pr-merged';
    public const string ARRIVED = 'arrived';
    public const string LEFT = 'left';

    /** The events with no slot. */
    public const array NAMED = [self::PR_OPENED, self::AGENT_REVIEW_PASSED, self::READY_TO_MERGE, self::PR_MERGED];

    /** The events of a slot. */
    public const array SLOT = [self::ARRIVED, self::LEFT];

    public function __construct(
        public string $name,
        public ?string $slot = null,
    ) {
    }
}
