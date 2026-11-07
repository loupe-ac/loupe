<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Tells what a card does in a column of a project. Workflow implements it. */
interface ColumnStarts
{
    /** The translated hint of the slot of the column, or null when automation is off, the project has no template, or the slot has none. */
    public function hint(Uuid $projectId, ColumnRef $column): ?string;
}
