<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** What a marker store write changed. */
final readonly class MarkerWrite
{
    /**
     * @param bool         $changed true when either set differed from the stored one
     * @param list<string> $keys    the keys of the markers that differ, empty when only the first read of the inbox set changed
     */
    public function __construct(
        public bool $changed,
        public array $keys = [],
    ) {
    }
}
