<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric\Formula;

/** A leading plus or minus. */
final readonly class SignNode implements FormulaNode
{
    public function __construct(
        public bool $negative,
        public FormulaNode $operand,
        public int $position,
    ) {
    }
}
