<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** The limits of the live output log of a run. */
final class WorkerRunOutputLog
{
    /** The most bytes the server stores for one run. */
    public const int MAX_BYTES = 1048576;

    /** The most bytes one append request carries. */
    public const int MAX_APPEND_BYTES = 262144;

    /** The most bytes one browser read of the log answers. */
    public const int MAX_READ_BYTES = 65536;

    private function __construct()
    {
    }
}
