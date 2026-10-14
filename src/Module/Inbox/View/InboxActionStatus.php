<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Inbox\Entity\InboxAction;

/** The status line of one action. The label key and its parameters go to the translator. */
final readonly class InboxActionStatus
{
    /** @param array<string, string> $labelParameters */
    public function __construct(
        public InboxAction $action,
        public InboxActionState $state,
        public string $labelKey,
        public array $labelParameters = [],
        public bool $cancellable = false,
        /** True while no live bridge can take a queued command. */
        public bool $offline = false,
        /** When the state began. */
        public ?\DateTimeImmutable $at = null,
    ) {
    }
}
