<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** Keeps the result of a read for the length of one pass, so the readers of a fact build or of a page that read the same rows share one query. */
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

    /**
     * Opens a pass around the build, or joins the open one. Only a request that writes nothing may keep a pass open
     * for its whole length, because a read after a write inside the pass returns the stored rows.
     *
     * @template T
     *
     * @param \Closure(): T $build
     *
     * @return T
     */
    public function during(\Closure $build): mixed;
}
