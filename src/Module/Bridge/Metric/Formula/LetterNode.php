<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric\Formula;

final readonly class LetterNode implements FormulaNode
{
    public function __construct(
        public string $letter,
        public int $position,
    ) {
    }
}
