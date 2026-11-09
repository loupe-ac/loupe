<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric\Formula;

/** Carries the problem out of the parser and the type check to the code that builds a MetricFormula. */
final class FormulaException extends \RuntimeException
{
    public function __construct(
        public readonly FormulaProblem $problem,
    ) {
        parent::__construct($problem->key);
    }
}
