<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric\Formula;

/** A node of the tree that FormulaParser builds. The position is the 1-based character where the node starts. */
interface FormulaNode
{
    public int $position { get; }
}
