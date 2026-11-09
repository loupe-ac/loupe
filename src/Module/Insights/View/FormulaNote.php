<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

/** A note above a formula graph about a result that looks odd. The graph still draws. */
final readonly class FormulaNote
{
    public const string PERCENT_ABOVE_ONE_HUNDRED = 'analytics.metrics.formula.note.percent_above_one_hundred';
    public const string FORMAT_DISAGREES = 'analytics.metrics.formula.note.format_disagrees';
    public const string UNUSED_ROW = 'analytics.metrics.formula.note.unused_row';

    /** @param array<string, string> $parameters */
    public function __construct(
        public string $key,
        public array $parameters = [],
    ) {
    }
}
