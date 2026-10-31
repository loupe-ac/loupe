<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

/** The response of the owner, shown as a reply in the thread once the item is closed. */
final readonly class InboxThreadReply
{
    /** @param list<string> $options the texts of the picked options */
    public function __construct(
        /** The translation key of what the owner did, which takes %time%. */
        public string $verbKey,
        public ?\DateTimeImmutable $at,
        /** The verdict of a review; the verb already says what the owner did otherwise. */
        public ?InboxOutcomeLabel $outcome,
        public array $options,
        public ?string $note,
        /** An agent recorded the response for the owner. */
        public bool $throughAgent,
    ) {
    }
}
