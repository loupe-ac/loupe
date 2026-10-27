<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** The metric keys the app computes itself, which a template may not declare. Bridge implements it. */
interface ReservedMetricKeys
{
    /** @return list<string> */
    public function keys(): array;
}
