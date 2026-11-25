<?php

declare(strict_types=1);

namespace App\Module\Inbox\Form;

final class ChangeInboxDecisionRequest
{
    public function __construct(
        /** The index of one of the other options. */
        public ?string $option = null,
        /** The words of the owner, which win over a ticked option ("Something else"). */
        public ?string $custom = null,
        public ?string $note = null,
    ) {
    }

    /**
     * The choice to record: the own words when written, else the ticked option.
     *
     * @param list<string> $options the other options, in the order the form lists them
     */
    public function choice(array $options): string
    {
        $custom = trim($this->custom ?? '');
        if ('' !== $custom) {
            return $custom;
        }

        return null === $this->option ? '' : ($options[(int) $this->option] ?? '');
    }
}
