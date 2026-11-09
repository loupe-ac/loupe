<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric\Formula;

/** The position is that of the operator. */
final readonly class BinaryNode implements FormulaNode
{
    public function __construct(
        public FormulaOperator $operator,
        public FormulaNode $left,
        public FormulaNode $right,
        public int $position,
    ) {
    }
}
