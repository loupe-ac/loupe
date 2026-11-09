<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

/** Whether a formula can run, and the message in the language of the page when it cannot. */
final readonly class FormulaCheckResult
{
    public function __construct(
        public bool $ok,
        public ?string $error = null,
    ) {
    }
}
