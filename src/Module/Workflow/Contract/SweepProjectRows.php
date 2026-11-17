<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** Keeps a project row that changes rarely for a short time, while a sweep evaluation runs, so the next swept card of the project reads it from memory. */
interface SweepProjectRows
{
    /**
     * @template T
     *
     * @param \Closure(): T $read
     *
     * @return T the kept result while a sweep message is handled and the result is fresh, else a fresh read
     */
    public function remember(string $key, \Closure $read): mixed;
}
