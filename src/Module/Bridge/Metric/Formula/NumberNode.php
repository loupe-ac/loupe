<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric\Formula;

final readonly class NumberNode implements FormulaNode
{
    public function __construct(
        public float $value,
        public int $position,
    ) {
    }
}
