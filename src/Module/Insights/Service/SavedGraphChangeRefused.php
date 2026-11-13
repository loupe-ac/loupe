<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

/** A change of the settings of a saved graph that contradicts the graph or itself. */
final class SavedGraphChangeRefused extends \DomainException
{
    /**
     * @param non-empty-list<string> $fields the names of the arguments in conflict
     * @param string                 $reason the sentence that tells the caller what to do
     * @param bool                   $named  whether the message starts with the field names
     */
    public function __construct(
        public readonly array $fields,
        public readonly string $reason,
        bool $named = true,
    ) {
        parent::__construct($named ? implode(', ', $fields).': '.$reason : $reason);
    }
}
