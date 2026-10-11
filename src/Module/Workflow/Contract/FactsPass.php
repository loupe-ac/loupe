<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** Keeps the result of a read for the length of one fact pass, so the fact sources that read the same rows share one query. */
interface FactsPass
{
    /**
     * @template T
     *
     * @param \Closure(): T $read
     *
     * @return T the stored result while a pass is open, else a fresh read
     */
    public function remember(string $key, \Closure $read): mixed;
}
