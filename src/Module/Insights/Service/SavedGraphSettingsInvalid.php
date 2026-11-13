<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

/** A set of settings that the metric or the range of a graph cannot take. */
final class SavedGraphSettingsInvalid extends \DomainException
{
    /**
     * @param non-empty-list<array{field: string, message: string}> $problems
     */
    public function __construct(
        public readonly array $problems,
    ) {
        parent::__construct(implode("\n", array_map(static fn (array $problem): string => $problem['field'].': '.$problem['message'], $problems)));
    }
}
