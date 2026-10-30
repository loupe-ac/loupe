<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** A value key the workflow template declares. A card holds one value for each key. */
final readonly class TemplateValueKey
{
    /** Lowercase words and digits joined with hyphens, at most 64 characters. */
    public const string KEY_PATTERN = '/^(?=.{1,64}$)[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    /**
     * @param string $label   a translation key for the name of the value
     * @param string $caption a translation key for the short text beside the control
     * @param string $help    a translation key for the help text
     * @param string $history a translation key for the noun phrase that the card history uses
     */
    public function __construct(
        public string $key,
        public CardValueType $type,
        public string $label,
        public string $caption,
        public string $help,
        public string $history,
    ) {
    }
}
