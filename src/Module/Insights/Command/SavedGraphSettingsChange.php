<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

/** A partial change of the settings of a graph. The handler puts it on top of the settings the graph keeps under the lock. */
final readonly class SavedGraphSettingsChange
{
    public function __construct(
        /** @var array<string, string> the one-metric settings that the change passed */
        public array $passed = [],
        /** @var array<string, string> the rows, the formula and the format that the change passed */
        public array $formulaPassed = [],
        public bool $clearFormula = false,
    ) {
    }
}
